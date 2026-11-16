package com.echoledger.mobile.calls

import android.content.Context
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.data.ApiJson
import java.io.File
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer

/**
 * This phone's missed recordings (since v1.8, P0 0.2): the Activity items with their reason and fix button, and the
 * reports still to send. Kept 31 days in no-backup storage: a day past the 30 the server takes, so a report too old to
 * send is refused on the phone rather than lost on the way. Safe from any thread; [items] follows every change.
 */
object MissedRecordingStore {
    private const val KEEP_MS = 31L * 24 * 60 * 60 * 1000
    private const val MAX = 200

    private val _items = MutableStateFlow<List<MissedItem>>(emptyList())
    private var file: File? = null

    /** Newest first. */
    val items: StateFlow<List<MissedItem>> = _items.asStateFlow()

    @Synchronized
    fun all(context: Context): List<MissedItem> {
        load(context)
        return _items.value
    }

    /**
     * New items for what the detector found while [owner] is the account signed in; returns them, each with its own
     * `client_ref`. Each belongs to the account that was signed in when its call started (a sign-in as someone else
     * since then leaves it with the one before). A call already here (its app family and start time) is never added
     * twice.
     */
    @Synchronized
    fun add(
        context: Context,
        found: List<MissedRecordings.Found>,
        nowMs: Long = System.currentTimeMillis(),
        owner: Owner? = null
    ): List<MissedItem> {
        if (found.isEmpty()) return emptyList()
        val before = all(context)
        val switches = switches(context)
        val fresh = mutableListOf<MissedItem>()
        for (f in found) {
            val twice = (before + fresh).any {
                MissedRecordings.sameApp(it.app, f.app) && kotlin.math.abs(it.startedAtMs - f.startedAtMs) <= MissedRecordings.MATCH_MS
            }
            if (twice) continue
            val fix = f.fix.takeIf { it != f.reason.fix }?.name
            fresh += MissedItem(
                ref = UUID.randomUUID().toString(),
                app = f.app,
                startedAtMs = f.startedAtMs,
                durationS = f.durationS,
                reason = f.reason.wire,
                detail = f.detail,
                foundAtMs = nowMs,
                owner = accountAt(switches, f.startedAtMs, owner),
                fix = fix,
                endedAtMs = f.endedAtMs,
                logOnly = f.logOnly
            )
        }
        if (fresh.isEmpty()) return emptyList()
        save(context, fresh + before, nowMs)
        return fresh
    }

    @Synchronized
    fun change(context: Context, ref: String, transform: (MissedItem) -> MissedItem) {
        save(context, all(context).map { if (it.ref == ref) transform(it) else it })
    }

    fun markRead(context: Context, ref: String) = change(context, ref) { it.copy(read = true) }

    fun dismiss(context: Context, ref: String) = change(context, ref) { it.copy(read = true, dismissed = true) }

    fun sent(context: Context, ref: String, serverId: String?) = change(context, ref) { it.copy(sent = true, serverId = serverId) }

    fun refused(context: Context, ref: String) = change(context, ref) { it.copy(refused = true) }

    /**
     * A recording of the call turned up after all: the item leaves Activity and is never sent; one sent already has its
     * server copy marked read by the uploader ([MissedItem.serverCopyToRead]).
     */
    fun withdraw(context: Context, ref: String) = change(context, ref) { it.copy(read = true, dismissed = true, withdrawn = true) }

    /** Their server copies were marked read (or looked for and not there any more). */
    @Synchronized
    fun serverRead(context: Context, refs: Collection<String>) {
        if (refs.isEmpty()) return
        save(context, all(context).map { if (it.ref in refs) it.copy(serverRead = true) else it })
    }

    /**
     * A sign-in as someone else at [atMs] (since v1.8): until then [previous] was the account here. Kept beside the items
     * (no-backup storage, 31 days), never in the capture journal, which goes into "Copy diagnostics" without accounts.
     */
    @Serializable
    data class Switch(val atMs: Long, val previous: Owner)

    /**
     * A sign-in as [next] after [previous] at [atMs]: when it is someone else, the items found without an account are
     * stamped with [previous] (they were found under it), as recordings are, so they never show or go out under the new
     * account; and the switch is remembered, so a call found later still belongs to the account it was made under.
     */
    @Synchronized
    fun stamp(context: Context, previous: Owner?, next: Owner, atMs: Long = System.currentTimeMillis()) {
        if (previous == null || previous.mayUploadAs(next)) return
        val kept = (switches(context) + Switch(atMs, previous)).filter { it.atMs >= atMs - KEEP_MS }.takeLast(MAX_SWITCHES)
        runCatching { writeAtomically(switchesFile(context), ApiJson.encodeToString(ListSerializer(Switch.serializer()), kept)) }
        val items = all(context)
        if (items.none { it.owner == null }) return
        save(context, items.map { if (it.owner == null) it.copy(owner = previous) else it })
    }

    /**
     * The account signed in when a call started at [atMs] (pure): the one a later sign-in as someone else replaced, else
     * [current]. Without any switch after the call, it is the account here now.
     */
    fun accountAt(switches: List<Switch>, atMs: Long, current: Owner?): Owner? =
        switches.filter { it.atMs > atMs }.minByOrNull { it.atMs }?.previous ?: current

    @Synchronized
    fun switches(context: Context): List<Switch> = runCatching {
        ApiJson.decodeFromString(ListSerializer(Switch.serializer()), switchesFile(context).readText())
    }.getOrDefault(emptyList())

    private fun switchesFile(context: Context) = File(context.noBackupFilesDir, "missed-recordings-accounts.json")

    private const val MAX_SWITCHES = 50

    /** What [current] sees and sends: its own items (and those found before any account was known). */
    fun forAccount(items: List<MissedItem>, current: Owner?): List<MissedItem> =
        items.filter { !it.withdrawn && Owner.mayUpload(it.owner, current) }

    /** The server copies [current] marks read: its items read, dismissed or withdrawn here after they were sent. */
    fun serverCopiesToRead(items: List<MissedItem>, current: Owner?): List<MissedItem> =
        items.filter { it.serverCopyToRead && Owner.mayUpload(it.owner, current) }

    @Synchronized
    fun clear(context: Context) {
        load(context)
        file?.delete()
        switchesFile(context).delete()
        _items.value = emptyList()
    }

    private fun save(context: Context, list: List<MissedItem>, nowMs: Long = System.currentTimeMillis()) {
        load(context)
        val kept = list.filter { it.startedAtMs >= nowMs - KEEP_MS }.sortedByDescending { it.startedAtMs }.take(MAX)
        _items.value = kept
        val target = file ?: return
        runCatching { writeAtomically(target, ApiJson.encodeToString(ListSerializer(MissedItem.serializer()), kept)) }
    }

    private fun writeAtomically(target: File, text: String) {
        val tmp = File(target.parentFile, target.name + ".tmp")
        tmp.writeText(text)
        if (!tmp.renameTo(target)) {
            target.delete()
            tmp.renameTo(target)
        }
    }

    private fun load(context: Context) {
        val target = File(context.noBackupFilesDir, "missed-recordings.json")
        if (file == target) return
        file = target
        _items.value = runCatching {
            ApiJson.decodeFromString(ListSerializer(MissedItem.serializer()), target.readText())
        }.getOrDefault(emptyList())
    }
}
