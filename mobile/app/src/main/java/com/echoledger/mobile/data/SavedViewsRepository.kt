package com.echoledger.mobile.data

import android.content.Context
import com.echoledger.mobile.core.LibraryFilterSet
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.ApiException
import java.io.File
import java.util.UUID
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject

/**
 * What one of My views keeps (contract 2.0.0-p "Saved views"): the filters (a filter set in the contract's shape), the
 * search and how the list is shown, in the contract's words (`sort` newest…opened, `group` date…none, `density`). A sort
 * or group this app lacks is kept as it came and shows as Newest or Date.
 */
@Serializable
data class ViewSpec(
    val filters: JsonObject = JsonObject(emptyMap()),
    val search: String? = null,
    val sort: String = "newest",
    val group: String = "date",
    val density: String = "comfortable"
) {
    val filterSet: LibraryFilterSet get() = LibraryFilterSet.read(filters)

    /** As kept and compared: the filter set cleaned, the search trimmed (none: null). */
    fun clean(): ViewSpec = copy(filters = filterSet.clean().toJson(), search = search?.trim()?.take(200)?.ifEmpty { null })
}

/**
 * One of My views on this phone: its server id once the server has it (a view made offline has a `local-` id until
 * then), the `client_ref` it was made with, what waits to be sent ([pending] create, update or delete; [changed] says
 * which of name and spec an update sends), and the server's last copy of it ([saved]), which a refused change puts back.
 */
@Serializable
data class MyView(
    val id: String,
    val name: String,
    val spec: ViewSpec,
    val clientRef: String? = null,
    val pending: String? = null,
    val changed: Set<String> = emptySet(),
    val saved: Saved? = null
) {
    @Serializable
    data class Saved(val name: String, val spec: ViewSpec)
}

/** Why a view could not be made or changed here, before anything is sent. */
class ViewError(val reason: Reason, val name: String = "") : Exception(reason.name) {
    enum class Reason { NOT_READY, FULL, EMPTY, TOO_LONG, TAKEN, GONE }
}

/** A change the server refused for good, given back or dropped: what the screen says. */
data class ViewProblem(val kind: Kind, val name: String) {
    enum class Kind { NOT_SAVED_FULL, NOT_SAVED, NOT_CHANGED, NOT_DELETED, GONE, ORDER_NOT_SAVED }
}

/**
 * My views, local first (contract 2.0.0-p "Saved views"): a copy per account on this phone, so they show and change
 * offline at once; each change is marked and sent to `/api/views` when the server can be reached (a view made offline
 * goes with its own `client_ref`, so sending it twice never makes it twice). When the library sync's `views_version`
 * differs from the copy's, the copy is read again. The copy is the account's: the server, the email and the workspace
 * ([accountKey]); none shows and none is sent until all three are known, and the last one is remembered for offline.
 * A permanent refusal (`validation_failed`, `views_limit`, `view_not_found`) drops a new view or gives a change back,
 * and says why ([problems]); every other error leaves the change waiting. Signing out deletes the copy ([clear]).
 */
class SavedViewsRepository(
    context: Context,
    private val cipher: SecretCipher,
    private val scope: CoroutineScope,
    private val api: suspend () -> ApiClient?
) {
    @Serializable
    private data class Copy(val views: List<MyView> = emptyList(), val version: Long? = null, val reorder: Boolean = false)

    @Serializable
    private data class Kept(val accounts: Map<String, Copy> = emptyMap(), val last: String? = null)

    private val file = File(context.noBackupFilesDir, "views.bin")
    private var kept: Kept = load() ?: Kept()
    private var account: String? = null

    /** Each account switch (or sign-out) starts a new generation; what an older sync hears back is dropped. */
    private var gen = 0
    private val syncing = Mutex()

    @Volatile private var again = false

    private val _views = MutableStateFlow<List<MyView>>(emptyList())

    /** The account's views in their order (one waiting to be deleted shows nowhere); empty until the account is known. */
    val views: StateFlow<List<MyView>> = _views

    private val _ready = MutableStateFlow(false)

    /** Whether the account is known (the views can show and change). */
    val ready: StateFlow<Boolean> = _ready

    private val _problems = MutableSharedFlow<ViewProblem>(extraBufferCapacity = 16)
    val problems: SharedFlow<ViewProblem> = _problems

    /** Uses [key]'s copy ([accountKey]); null shows none. A new account syncs at once. */
    fun use(key: String?) {
        synchronized(this) {
            if (key == account) return
            gen++
            account = key
            again = false
            if (key != null && kept.last != key) {
                kept = kept.copy(last = key)
                save()
            }
            publish()
        }
        if (key != null) scope.launch(Dispatchers.IO) { sync() }
    }

    /** The account whose views showed last with [server] (to show them while the account can't be read), or null. */
    fun lastAccount(server: String?): String? = synchronized(this) {
        val host = host(server)
        kept.last?.takeIf { host.isNotEmpty() && it.startsWith("$host|") }
    }

    /** Signed out: every account's copy is deleted from this phone, and whose they were is forgotten. */
    fun clear() = synchronized(this) {
        gen++
        account = null
        kept = Kept()
        file.delete()
        publish()
    }

    /** The version the copy is (null: never read from the server). */
    fun version(): Long? = synchronized(this) { account?.let { kept.accounts[it]?.version } }

    // ---- Changes made here ------------------------------------------------------------------------------------------

    /** Makes a view (at the end of My views) and sends it; throws [ViewError] when it can't be made. */
    fun create(name: String, spec: ViewSpec): MyView {
        val view = synchronized(this) {
            val copy = copy() ?: throw ViewError(ViewError.Reason.NOT_READY)
            if (copy.views.count { it.pending != DELETE } >= MAX_VIEWS) throw ViewError(ViewError.Reason.FULL)
            check(name, copy.views, except = null)
            val ref = UUID.randomUUID().toString()
            val made = MyView(LOCAL + ref, spaces(name), spec.clean(), clientRef = ref, pending = CREATE)
            keep(copy.copy(views = copy.views + made))
            made
        }
        later()
        return view
    }

    fun rename(id: String, name: String) {
        synchronized(this) {
            val copy = copy() ?: throw ViewError(ViewError.Reason.NOT_READY)
            find(copy, id)
            check(name, copy.views, except = id)
            keep(copy.copy(views = copy.views.map { if (it.id == id) it.touched(NAME).copy(name = spaces(name)) else it }))
        }
        later()
    }

    /** Saves the view's filters, search and display again (Save changes, while it is open and changed). */
    fun update(id: String, spec: ViewSpec) {
        synchronized(this) {
            val copy = copy() ?: throw ViewError(ViewError.Reason.NOT_READY)
            find(copy, id)
            keep(copy.copy(views = copy.views.map { if (it.id == id) it.touched(SPEC).copy(spec = spec.clean()) else it }))
        }
        later()
    }

    fun remove(id: String) {
        synchronized(this) {
            val copy = copy() ?: throw ViewError(ViewError.Reason.NOT_READY)
            val view = find(copy, id)
            // Never sent: it just goes. Else it waits to be deleted on the server (and shows nowhere meanwhile).
            val views = if (view.pending == CREATE) {
                copy.views - view
            } else {
                copy.views.map { if (it.id == id) it.copy(pending = DELETE) else it }
            }
            keep(copy.copy(views = views))
        }
        later()
    }

    /** Moves a view [delta] places (−1 up, +1 down) in My views. */
    fun move(id: String, delta: Int) {
        synchronized(this) {
            val copy = copy() ?: throw ViewError(ViewError.Reason.NOT_READY)
            find(copy, id)
            val live = copy.views.filter { it.pending != DELETE }.toMutableList()
            val from = live.indexOfFirst { it.id == id }
            val to = (from + delta).coerceIn(0, live.lastIndex)
            if (from == to) return
            live.add(to, live.removeAt(from))
            keep(copy.copy(views = live + copy.views.filter { it.pending == DELETE }, reorder = true))
        }
        later()
    }

    private fun MyView.touched(field: String): MyView = if (pending == CREATE) this else copy(pending = UPDATE, changed = changed + field)

    private fun find(copy: Copy, id: String): MyView =
        copy.views.firstOrNull { it.id == id && it.pending != DELETE } ?: throw ViewError(ViewError.Reason.GONE)

    private fun check(name: String, views: List<MyView>, except: String?) {
        val n = spaces(name)
        if (n.isEmpty()) throw ViewError(ViewError.Reason.EMPTY)
        if (n.length > NAME_MAX) throw ViewError(ViewError.Reason.TOO_LONG)
        views.firstOrNull { it.id != except && it.pending != DELETE && it.name.equals(n, ignoreCase = true) }
            ?.let { throw ViewError(ViewError.Reason.TAKEN, it.name) }
    }

    private fun later() {
        scope.launch(Dispatchers.IO) { sync() }
    }

    // ---- With the server --------------------------------------------------------------------------------------------

    /**
     * Sends what waits, in order, then reads the server's copy when [serverVersion] (the library sync's) is not the
     * copy's, or after sending. One sync at a time; a change made while one runs is sent by one more round ([wait]:
     * after the one running instead). Stops quietly when the server can't be reached: the copy stays and is sent next time.
     */
    suspend fun sync(serverVersion: Long? = null, wait: Boolean = false) {
        if (wait) {
            syncing.lock()
        } else if (!syncing.tryLock()) {
            again = true
            return
        }
        try {
            var rounds = 0
            do {
                again = false
                round(serverVersion)
            } while (again && ++rounds < 5 && synchronized(this) { copy()?.views?.any { it.pending != null } == true })
        } finally {
            syncing.unlock()
        }
    }

    private suspend fun round(serverVersion: Long?) {
        val (g, start) = synchronized(this) { gen to (copy() ?: return) }
        val client = runCatching { api() }.getOrNull() ?: return
        var sent = false
        for (waiting in start.views.filter { it.pending != null }) {
            val now = synchronized(this) { if (g != gen) return else copy()?.views?.firstOrNull { it.id == waiting.id } } ?: continue
            try {
                when (now.pending) {
                    CREATE -> {
                        val answer = client.createView(body(now, all = true))
                        sent = true
                        made(g, now, answer)
                    }

                    UPDATE -> {
                        val answer = client.updateView(now.id, body(now, all = false))
                        sent = true
                        changed(g, now, answer)
                    }

                    DELETE -> {
                        val answer = client.deleteView(now.id)
                        sent = true
                        edit(g) { c -> c.copy(views = c.views.filter { it.id != now.id }, version = answer.viewsVersion) }
                    }
                }
            } catch (e: ApiException) {
                if (e.code !in REFUSALS) return
                sent = true
                refused(g, now, e.code)
            } catch (e: Exception) {
                // Offline, a busy or held server, or one without saved views yet: kept here, sent next time.
                return
            }
        }
        val after = synchronized(this) { if (g != gen) return else copy() } ?: return
        if (after.views.any { it.pending != null }) {
            again = true
            return
        }
        if (after.reorder) {
            try {
                val answer = client.orderViews(after.views.map { it.id })
                sent = true
                edit(g) { c -> c.copy(reorder = false, version = answer.viewsVersion) }
            } catch (e: ApiException) {
                if (e.code !in REFUSALS) return
                // Refused (a view deleted elsewhere meanwhile): the server's order is read back.
                sent = true
                edit(g) { c -> c.copy(reorder = false, version = null) }
                _problems.tryEmit(ViewProblem(ViewProblem.Kind.ORDER_NOT_SAVED, ""))
            } catch (e: Exception) {
                return
            }
        }
        val version = synchronized(this) { if (g != gen) return else copy()?.version }
        if (!sent && version != null && (serverVersion == null || serverVersion == version)) return
        val answer = try {
            client.views()
        } catch (e: Exception) {
            return
        }
        edit(g) { c ->
            // Something changed here while it was read: kept, and sent on the next round.
            if (c.views.any { it.pending != null } || c.reorder) {
                c
            } else {
                Copy(answer.data.sortedBy { it.position }.map(::fromServer), answer.viewsVersion)
            }
        }
    }

    /** A new view the server made: the server's id from now on, keeping its place and what changed since it was sent. */
    private fun made(g: Int, sent: MyView, answer: SavedViewAnswer) {
        val server = fromServer(answer.data)
        var orphan = false
        edit(g) { c ->
            val here = c.views.firstOrNull { it.id == sent.id }
            if (here == null) {
                orphan = true
                return@edit c.copy(version = answer.viewsVersion)
            }
            val same = here.pending == CREATE && here.name == sent.name && here.spec == sent.spec
            val next = when {
                same -> here.copy(id = server.id, pending = null, changed = emptySet(), saved = server.saved)
                else -> here.copy(id = server.id, pending = UPDATE, changed = setOf(NAME, SPEC), saved = server.saved)
            }
            c.copy(views = c.views.map { if (it.id == sent.id) next else it }, version = answer.viewsVersion)
        }
        // Deleted here while it was on its way: it goes on the server too, on the next round.
        if (orphan) {
            edit(g) { c -> c.copy(views = c.views + server.copy(pending = DELETE)) }
            again = true
        }
    }

    private fun changed(g: Int, sent: MyView, answer: SavedViewAnswer) {
        val saved = fromServer(answer.data).saved
        edit(g) { c ->
            c.copy(
                views = c.views.map { v ->
                    when {
                        v.id != sent.id -> v

                        v.pending == UPDATE && v.name == sent.name && v.spec == sent.spec ->
                            v.copy(pending = null, changed = emptySet(), saved = saved)

                        else -> v.copy(saved = saved)
                    }
                },
                version = answer.viewsVersion
            )
        }
    }

    /** A permanent refusal: a new view is dropped, a change given back (the server's copy then read again); said. */
    private fun refused(g: Int, view: MyView, code: String?) {
        val kind = when (view.pending) {
            CREATE -> if (code == "views_limit") ViewProblem.Kind.NOT_SAVED_FULL else ViewProblem.Kind.NOT_SAVED
            UPDATE -> if (code == "view_not_found") ViewProblem.Kind.GONE else ViewProblem.Kind.NOT_CHANGED
            else -> if (code == "view_not_found") null else ViewProblem.Kind.NOT_DELETED
        }
        edit(g) { c ->
            val views = when {
                view.pending == CREATE || code == "view_not_found" -> c.views.filter { it.id != view.id }
                else -> c.views.map { it.givenBack(view.id) }
            }
            c.copy(views = views, version = null)
        }
        kind?.let { _problems.tryEmit(ViewProblem(it, view.name)) }
    }

    /** [id] as the server last had it, nothing waiting (a refused change given back). */
    private fun MyView.givenBack(id: String): MyView =
        if (this.id != id) this else copy(name = saved?.name ?: name, spec = saved?.spec ?: spec, pending = null, changed = emptySet())

    private fun body(view: MyView, all: Boolean): JsonObject = buildJsonObject {
        val spec = view.spec.clean()
        if (all || NAME in view.changed) put("name", JsonPrimitive(spaces(view.name)))
        if (all || SPEC in view.changed) {
            put("filters", spec.filters)
            put("search", spec.search?.let { JsonPrimitive(it) } ?: JsonNull)
            put("sort", JsonPrimitive(spec.sort))
            put("group", JsonPrimitive(spec.group))
            put("density", JsonPrimitive(spec.density))
        }
        if (all) view.clientRef?.let { put("client_ref", JsonPrimitive(it)) }
    }

    // ---- The copy ---------------------------------------------------------------------------------------------------

    private fun copy(): Copy? = account?.let { kept.accounts[it] ?: Copy() }

    /** Keeps [next] as the account's copy and shows it (called holding the lock). */
    private fun keep(next: Copy) {
        val key = account ?: return
        kept = kept.copy(accounts = kept.accounts + (key to next))
        save()
        publish()
    }

    /** Changes the copy for generation [g]; a change from another generation (another account's sync) is dropped. */
    private fun edit(g: Int, change: (Copy) -> Copy) = synchronized(this) {
        if (g != gen) return@synchronized
        copy()?.let { keep(change(it)) }
    }

    private fun publish() {
        _ready.value = account != null
        _views.value = copy()?.views?.filter { it.pending != DELETE }.orEmpty()
    }

    private fun load(): Kept? = runCatching {
        ApiJson.decodeFromString(Kept.serializer(), String(cipher.decrypt(file.readBytes(), CONTEXT)))
    }.getOrNull()

    private fun save() {
        runCatching {
            val tmp = File(file.parentFile, "views.bin.tmp")
            tmp.writeBytes(cipher.encrypt(ApiJson.encodeToString(Kept.serializer(), kept).toByteArray(), CONTEXT))
            tmp.renameTo(file)
        }
    }

    companion object {
        const val MAX_VIEWS = 30
        const val NAME_MAX = 60
        private const val LOCAL = "local-"
        private const val CREATE = "create"
        private const val UPDATE = "update"
        private const val DELETE = "delete"
        private const val NAME = "name"
        private const val SPEC = "spec"
        private val CONTEXT = "echoledger.views".toByteArray()

        /** The server's answers that will not change by sending again. */
        val REFUSALS = setOf("validation_failed", "views_limit", "view_not_found")

        private fun spaces(name: String) = name.trim().replace(Regex("\\s+"), " ")

        private fun host(server: String?) = server?.trim()?.trimEnd('/')?.lowercase().orEmpty()

        /** Whose views: the server, the email and the workspace together; null until all three are known. */
        fun accountKey(server: String?, email: String?, workspace: Long?): String? {
            val h = host(server)
            val who = email?.trim()?.lowercase().orEmpty()
            return if (h.isNotEmpty() && who.isNotEmpty() && workspace != null) "$h|$who|$workspace" else null
        }

        /** A view as the server sends it, as this phone keeps it. */
        fun fromServer(dto: SavedViewDto): MyView {
            val spec = ViewSpec(
                filters = LibraryFilterSet.read(dto.filters).toJson(),
                search = dto.search?.ifBlank { null },
                sort = dto.sort ?: "newest",
                group = dto.group ?: "date",
                density = dto.density ?: "comfortable"
            )
            return MyView(dto.id, dto.name, spec, dto.clientRef, saved = MyView.Saved(dto.name, spec))
        }
    }
}
