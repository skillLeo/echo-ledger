package com.echoledger.mobile.ui.design

import androidx.compose.runtime.Composable
import androidx.compose.runtime.produceState
import androidx.compose.ui.graphics.ImageBitmap
import java.util.concurrent.ConcurrentHashMap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Installed apps' icons, loaded once off the main thread and kept for the life of the process (since 1.3.2: loading
 * them from the PackageManager while a list scrolled made the Library stutter). A missing app is remembered too.
 */
object AppIcons {
    private class Slot(val icon: ImageBitmap?)

    private val cache = ConcurrentHashMap<String, Slot>()

    fun isCached(key: String): Boolean = cache.containsKey(key)

    fun cached(key: String): ImageBitmap? = cache[key]?.icon

    suspend fun load(key: String, block: () -> ImageBitmap?): ImageBitmap? {
        cache[key]?.let { return it.icon }
        val icon = withContext(Dispatchers.IO) { runCatching(block).getOrNull() }
        cache[key] = Slot(icon)
        return icon
    }
}

/** The icon under [key]: at once when cached, otherwise null until [load] has run on a background thread. */
@Composable
fun rememberAppIcon(key: String, load: () -> ImageBitmap?): ImageBitmap? {
    val known = AppIcons.isCached(key)
    return produceState(AppIcons.cached(key), key) {
        if (!known) value = AppIcons.load(key, load)
    }.value
}
