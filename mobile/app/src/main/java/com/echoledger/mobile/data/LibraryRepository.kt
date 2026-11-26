package com.echoledger.mobile.data

import android.content.Context
import java.io.File
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.Serializable

/**
 * Unified library from every device (phone, desktop, web) with tombstone sync and an encrypted offline cache.
 * Last-write-wins on `updated_at` (docs/API.md "Library & sync").
 */
class LibraryRepository(
    context: Context,
    private val cipher: SecretCipher,
    private val api: suspend () -> com.echoledger.mobile.net.ApiClient?
) {
    /**
     * [format] 2 since 2.0.0-p: the items carry `ended_at`, `facets` and `processing_since`; 3 since the phone reads
     * `title_source` (2.0.0-k, Rename's U19). A cache kept before has none of them, so its next sync reads the whole
     * library again (and replaces it) instead of only what changed.
     */
    @Serializable
    private data class Cache(
        val since: String? = null,
        val items: List<LibraryItem> = emptyList(),
        val format: Int = 1,
        val waiting: List<LibraryItem> = emptyList()
    )

    private val file = File(context.noBackupFilesDir, "library.bin")
    private val lock = Mutex()
    private var since: String? = null

    /**
     * Each [clear] (a sign-out) starts a new generation: a sync or a waiting read that was still on the network then
     * is dropped when it lands (nothing shown, nothing written), so the person who signed out never comes back.
     */
    @Volatile private var gen = 0

    /** Each waiting read is numbered; an answer is kept only when no later-asked read's answer was kept before it. */
    private var waitingAsked = 0L
    private var waitingKept = 0L

    /** The cache is from before 2.0.0-p: the next sync reads everything and replaces it. */
    private var stale = false
    private val _items = MutableStateFlow(emptyList<LibraryItem>())
    val items: StateFlow<List<LibraryItem>> = _items

    private val _waiting = MutableStateFlow(emptyList<LibraryItem>())

    /**
     * The person's calls still waiting for Keep or Discard (session `recording`) on any device, which the sync leaves out
     * (contract 2.0.0-p): Needs you lists them. Read whole each time ([refreshWaiting]).
     */
    val waiting: StateFlow<List<LibraryItem>> = _waiting

    private val _viewsVersion = MutableStateFlow<Long?>(null)

    /** The person's saved views' version, as the last sync said (2.0.0-p); null before one or from an older server. */
    val viewsVersion: StateFlow<Long?> = _viewsVersion

    init {
        load()?.let {
            stale = it.format < FORMAT
            since = it.since.takeUnless { _ -> stale }
            _items.value = it.items
            _waiting.value = it.waiting
        }
    }

    suspend fun refresh(): Int = lock.withLock {
        val g = gen
        val client = api() ?: return@withLock 0
        var cursor: String? = null
        var changed = 0
        var serverTime: String? = null
        var version: Long? = null
        // The whole read owed after an update of the cache's format replaces the items: nothing old stays.
        var current = if (stale) emptyList() else _items.value
        val from = since
        do {
            val page = client.library(from, cursor)
            changed += page.data.size
            if (serverTime == null) {
                serverTime = page.serverTime
                version = page.viewsVersion
            }
            current = merge(current, page.data)
            cursor = page.nextCursor
        } while (cursor != null)
        // Signed out meanwhile: what this read brought belongs to the person who left.
        synchronized(this) {
            if (g != gen) return@withLock 0
            since = serverTime // the do/while runs once and always sets it
            stale = false
            version?.let { _viewsVersion.value = it }
            _items.value = current
            save(Cache(since, current))
        }
        changed
    }

    /**
     * The calls still waiting for Keep or Discard (`GET /api/library?status=recording`, every page), in place of the
     * ones read before. Read without the library's lock (a tap marking a recording opened never waits for it), and
     * kept (the cache written) only when they changed. Throws like [refresh] when the server can't be reached; the
     * last ones stay.
     */
    suspend fun refreshWaiting() {
        val (g, asked) = synchronized(this) { gen to ++waitingAsked }
        val client = api() ?: return
        val found = mutableListOf<LibraryItem>()
        var cursor: String? = null
        var pages = 0
        do {
            val page = client.library(since = null, cursor = cursor, status = "recording")
            found += page.data.filter { !it.deleted && it.status == "recording" }
            cursor = page.nextCursor?.takeIf { it != cursor }
        } while (cursor != null && ++pages < MAX_WAITING_PAGES)
        val next = found.distinctBy { it.id }
        lock.withLock {
            synchronized(this) {
                // Signed out meanwhile, or a read asked later has answered already: this answer is older news.
                if (g != gen || asked <= waitingKept) return
                waitingKept = asked
                if (next == _waiting.value) return
                _waiting.value = next
                save(Cache(since, _items.value))
            }
        }
    }

    /**
     * The generation now: a caller takes it before its network call and hands it to the local write that follows
     * ([markOpenedLocally], [setViewsLocally], [upsertLocally]), which then changes nothing when a sign-out came between.
     */
    fun generation(): Int = gen

    /** Marks [id] opened here at once (the dot goes away before the server hears of it), unless signed out since [expected]. */
    suspend fun markOpenedLocally(id: String, expected: Int) = lock.withLock {
        synchronized(this) {
            if (expected != gen) return@withLock
            val current = _items.value
            if (current.none { it.id == id && it.opened == false }) return@withLock
            val updated = current.map { if (it.id == id) it.copy(opened = true) else it }
            _items.value = updated
            save(Cache(since, updated))
        }
    }

    /**
     * Marks [ids] read ([opened]) or unread here (contract 2.0.0-y: the ones the server did), unless signed out since
     * [expected]; nothing is written when none changes.
     */
    suspend fun setOpenedLocally(ids: Collection<String>, opened: Boolean, expected: Int) = lock.withLock {
        synchronized(this) {
            if (expected != gen) return@withLock
            val wanted = ids.toSet()
            val current = _items.value
            if (current.none { it.id in wanted && it.opened != opened }) return@withLock
            val updated = current.map { if (it.id in wanted && it.opened != opened) it.copy(opened = opened) else it }
            _items.value = updated
            save(Cache(since, updated))
        }
    }

    /** Marks [id] unread here at once (Mark as unread, contract 2.0.0-t), unless signed out since [expected]. */
    suspend fun markUnopenedLocally(id: String, expected: Int) = setOpenedLocally(listOf(id), false, expected)

    /**
     * Gives [id]'s row the views state the server has now (its `/views`): a view that stopped (failed after 20 minutes
     * with nothing done) does not change the recording, so no sync brings it. Nothing changes when signed out since
     * [expected] (the generation taken before asking the server).
     */
    suspend fun setViewsLocally(id: String, views: RowViewsDto, expected: Int) = lock.withLock {
        synchronized(this) {
            if (expected != gen) return@withLock
            val current = _items.value
            val updated = current.map { item ->
                if (item.id == id) item.copy(transcript = item.transcript?.copy(views = views)) else item
            }
            if (updated == current) return@withLock
            _items.value = updated
            save(Cache(since, updated))
        }
    }

    /**
     * Puts the server's answer for one recording ([item]) in the Library now, before the next sync brings it; nothing
     * when signed out since [expected] (the generation taken before asking the server): the answer is the last person's.
     */
    suspend fun upsertLocally(item: LibraryItem, expected: Int) = lock.withLock {
        synchronized(this) {
            if (expected != gen) return@withLock
            val current = _items.value
            val updated = if (current.any { it.id == item.id }) {
                current.map {
                    if (it.id ==
                        item.id
                    ) {
                        item
                    } else {
                        it
                    }
                }
            } else {
                listOf(item) + current
            }
            _items.value = updated
            save(Cache(since, updated))
        }
    }

    /** True once a sync has completed (the server time of the last sync is known). */
    fun hasSynced(): Boolean = since != null

    /** Signed out: the items, the sync point, the waiting calls and the cache go, and what is still on its way is dropped. */
    fun clear() = synchronized(this) {
        gen++
        since = null
        stale = false
        _items.value = emptyList()
        _waiting.value = emptyList()
        _viewsVersion.value = null
        file.delete()
    }

    private fun load(): Cache? = runCatching {
        val plain = cipher.decrypt(file.readBytes(), CONTEXT)
        ApiJson.decodeFromString(Cache.serializer(), String(plain))
    }.getOrNull()

    /** Keeps [cache] in the current format (still the old one while a whole read is owed). */
    private fun save(cache: Cache) {
        val kept = cache.copy(format = if (stale) 1 else FORMAT, waiting = _waiting.value)
        val enc = cipher.encrypt(ApiJson.encodeToString(Cache.serializer(), kept).toByteArray(), CONTEXT)
        val tmp = File(file.parentFile, "library.bin.tmp")
        tmp.writeBytes(enc)
        tmp.renameTo(file)
    }

    companion object {
        private val CONTEXT = "echoledger.library".toByteArray()

        /** The cache's format: 2 since 2.0.0-p, 3 since title_source (see [Cache]). */
        private const val FORMAT = 3

        /** Waiting calls are a few: at most this many pages of them are read. */
        private const val MAX_WAITING_PAGES = 10

        /** Pure merge (unit-tested): tombstones remove, newer versions replace, unknown items insert. */
        fun merge(existing: List<LibraryItem>, incoming: List<LibraryItem>): List<LibraryItem> {
            val byId = LinkedHashMap<String, LibraryItem>()
            existing.forEach { byId[it.id] = it }
            for (item in incoming) {
                val old = byId[item.id]
                when {
                    item.deleted -> byId.remove(item.id)
                    old == null || old.updatedAt <= item.updatedAt -> byId[item.id] = item
                }
            }
            return byId.values.sortedByDescending { it.startedAt ?: "" }
        }
    }
}
