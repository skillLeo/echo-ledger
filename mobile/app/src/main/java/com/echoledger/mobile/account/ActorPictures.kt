package com.echoledger.mobile.account

import android.graphics.BitmapFactory
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Deferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.async

/**
 * People's pictures shared by every row that shows them (contract 2.0.0-aa, lead decision 27): one fetch and one
 * decoded picture per server, person and version, however many Activity items name them. Thirty shares from one
 * teammate ask once. The copy on disk is [AvatarCache]'s; bytes it holds that don't decode are forgotten and asked for
 * again. No picture (a 403 or 404, bytes that don't decode, no connection) is remembered too, so scrolling and
 * redrawing never ask again; it is asked again for a new signed address, or [missMs] later. A load runs on its own
 * scope, so a row scrolled away mid-load doesn't cancel it for the others. Cleared with [AvatarCache.clearAll].
 */
class SharedPictures<T : Any>(
    private val decode: (ByteArray) -> T?,
    private val missMs: Long = 5 * 60_000L,
    private val now: () -> Long = System::currentTimeMillis
) {
    private class Entry<T>(val url: String, val at: Long, val picture: Deferred<T?>)

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val entries = HashMap<String, Entry<T>>()

    /**
     * [person]'s picture of [version] on [cache]'s server ([origin]), or null for their initials. [fetch] downloads it
     * from [url], null when the server no longer gives it.
     */
    suspend fun picture(
        cache: AvatarCache,
        origin: String,
        person: String,
        version: Long,
        url: String,
        fetch: suspend (String) -> ByteArray?
    ): T? {
        val key = "$origin\n$person\n$version"
        val shared = synchronized(entries) {
            val known = entries[key]
            if (known != null && reusable(known, url)) {
                known.picture
            } else {
                scope.async { load(cache, person, version, url, fetch) }.also { entries[key] = Entry(url, now(), it) }
            }
        }
        return shared.await()
    }

    private fun reusable(entry: Entry<T>, url: String): Boolean {
        if (!entry.picture.isCompleted) return true
        @OptIn(kotlinx.coroutines.ExperimentalCoroutinesApi::class)
        val done = runCatching { entry.picture.getCompleted() }.getOrNull()
        return done != null || (entry.url == url && now() - entry.at < missMs)
    }

    private suspend fun load(cache: AvatarCache, person: String, version: Long, url: String, fetch: suspend (String) -> ByteArray?): T? {
        cache.get(person, version)?.let { kept ->
            decode(kept)?.let { return it }
            // A copy that doesn't draw (cut short, damaged) is no copy: forgotten and asked for again.
            cache.forget(person)
        }
        val bytes = runCatching { fetch(url) }.getOrNull() ?: return null
        val picture = decode(bytes) ?: return null
        cache.put(person, version, bytes)
        return picture
    }

    /** Forgets every picture held in memory (sign-out, another account). */
    fun clear() {
        synchronized(entries) { entries.clear() }
    }
}

/** The pictures of the people Activity items are about, as the rows draw them. */
object ActorPictures {
    val shared = SharedPictures(decode = { bytes -> BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap() })

    init {
        AvatarCache.onClearAll { shared.clear() }
    }
}
