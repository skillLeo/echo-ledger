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
    @Serializable
    private data class Cache(val since: String? = null, val items: List<LibraryItem> = emptyList())

    private val file = File(context.noBackupFilesDir, "library.bin")
    private val lock = Mutex()
    private var since: String? = null
    private val _items = MutableStateFlow(emptyList<LibraryItem>())
    val items: StateFlow<List<LibraryItem>> = _items

    init {
        load()?.let {
            since = it.since
            _items.value = it.items
        }
    }

    suspend fun refresh(): Int = lock.withLock {
        val client = api() ?: return@withLock 0
        var cursor: String? = null
        var changed = 0
        var serverTime: String? = null
        var current = _items.value
        do {
            val page = client.library(since, cursor)
            changed += page.data.size
            if (serverTime == null) serverTime = page.serverTime
            current = merge(current, page.data)
            cursor = page.nextCursor
        } while (cursor != null)
        since = serverTime // the do/while runs once and always sets it
        _items.value = current
        save(Cache(since, current))
        changed
    }

    fun clear() {
        since = null
        _items.value = emptyList()
        file.delete()
    }

    private fun load(): Cache? = runCatching {
        val plain = cipher.decrypt(file.readBytes(), CONTEXT)
        ApiJson.decodeFromString(Cache.serializer(), String(plain))
    }.getOrNull()

    private fun save(cache: Cache) {
        val enc = cipher.encrypt(ApiJson.encodeToString(Cache.serializer(), cache).toByteArray(), CONTEXT)
        val tmp = File(file.parentFile, "library.bin.tmp")
        tmp.writeBytes(enc)
        tmp.renameTo(file)
    }

    companion object {
        private val CONTEXT = "echoledger.library".toByteArray()

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
