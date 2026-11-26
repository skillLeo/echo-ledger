package com.echoledger.mobile.account

import android.graphics.BitmapFactory
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Deferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.async

/**
 * People's pictures shared by every row that shows them (contract 2.0.0-aa, decision 27): one fetch and one
 * decoded picture per server, person and version, however many Activity items name them. Thirty shares from one
 * teammate ask once. The copy on disk is [AvatarCache]'s; bytes it holds that don't decode are forgotten and asked for
 * again. No picture (a 403 or 404, bytes that don't decode, no connection) is remembered too, so scrolling and
 * redrawing never ask again; it is asked again for a new signed address, or [missMs] later. A load runs on its own
 * scope, so a row scrolled away mid-load doesn't cancel it for the others. A disk that fails (full, or its folder gone
 * at sign-out) never fails a load: the picture still draws, it is just not kept. Cleared with [AvatarCache.clearAll]:
 * a load still running then keeps nothing, so no one's picture comes back after a sign-out.
 */
class SharedPictures<T : Any>(
    private val decode: (ByteArray) -> T?,
    private val missMs: Long = 5 * 60_000L,
    private val now: () -> Long = System::currentTimeMillis
) {
    private class Entry<T>(val url: String, val at: Long, val picture: Deferred<T?>)

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val entries = HashMap<String, Entry<T>>()

    /** The pictures drawn so far, by server, person and version: what a row shows at once ([peek]), before any load. */
    private val shown = HashMap<String, T>()

    /** Raised by [clear]: a load started before it keeps nothing. */
    @Volatile private var generation = 0L

    /**
     * [person]'s picture of [version] on [origin]'s server if one was drawn already, so a row coming back into view never
     * flashes initials.
     */
    fun peek(origin: String, person: String, version: Long): T? = synchronized(entries) { shown["$origin\n$person\n$version"] }

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
                val started = generation
                scope.async { load(cache, key, person, version, url, fetch, started) }.also { entries[key] = Entry(url, now(), it) }
            }
        }
        // Whatever went wrong, a row shows the initials: never an error into its effect.
        return try {
            shared.await()
        } catch (e: CancellationException) {
            throw e
        } catch (_: Throwable) {
            null
        }
    }

    private fun reusable(entry: Entry<T>, url: String): Boolean {
        if (!entry.picture.isCompleted) return true
        // A load that failed or was cancelled is no answer: asked again.
        @OptIn(kotlinx.coroutines.ExperimentalCoroutinesApi::class)
        val done = runCatching { entry.picture.getCompleted() }.getOrElse { return false }
        return done != null || (entry.url == url && now() - entry.at < missMs)
    }

    private suspend fun load(
        cache: AvatarCache,
        key: String,
        person: String,
        version: Long,
        url: String,
        fetch: suspend (String) -> ByteArray?,
        started: Long
    ): T? {
        runCatching { cache.get(person, version) }.getOrNull()?.let { kept ->
            runCatching { decode(kept) }.getOrNull()?.let { return drawn(key, it, started) }
            // A copy that doesn't draw (cut short, damaged) is no copy: forgotten and asked for again.
            runCatching { cache.forget(person) }
        }
        val bytes = runCatching { fetch(url) }.getOrNull() ?: return null
        val picture = runCatching { decode(bytes) }.getOrNull() ?: return null
        // Kept on the disk unless a sign-out came meanwhile; a disk that fails only means it isn't kept.
        if (started == generation) runCatching { cache.put(person, version, bytes) }
        return drawn(key, picture, started)
    }

    private fun drawn(key: String, picture: T, started: Long): T {
        synchronized(entries) { if (started == generation) shown[key] = picture }
        return picture
    }

    /** Forgets every picture held in memory (sign-out, another account); a load still running keeps nothing. */
    fun clear() {
        synchronized(entries) {
            generation++
            entries.clear()
            shown.clear()
        }
    }
}

/** The pictures of the people Activity items are about, as the rows draw them. */
object ActorPictures {
    val shared = SharedPictures(decode = { bytes -> BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap() })

    init {
        AvatarCache.onClearAll { shared.clear() }
    }
}
