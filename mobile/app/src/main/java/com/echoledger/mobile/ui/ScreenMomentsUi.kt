package com.echoledger.mobile.ui

import android.graphics.BitmapFactory
import android.util.LruCache
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.echoledger.mobile.core.ScreenMoments
import com.echoledger.mobile.data.ProjectScreenDto
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.Spinner
import com.echoledger.mobile.ui.design.tabular

/** Screen pictures already loaded in this run, by screen and size (their signed links change, the pictures do not). */
internal object ScreenImages {
    private val cache = LruCache<String, ImageBitmap>(40)

    fun get(key: String): ImageBitmap? = cache.get(key)

    fun put(key: String, image: ImageBitmap) {
        cache.put(key, image)
    }

    fun decode(bytes: ByteArray): ImageBitmap? = runCatching {
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap()
    }.getOrNull()
}

/** One picture, loaded once from its signed link; a spinner meanwhile and a quiet line when it cannot be loaded. */
@Composable
private fun ScreenPicture(vm: MainViewModel, screen: ScreenDto, full: Boolean, modifier: Modifier = Modifier) {
    val key = "${screen.id}:${if (full) "full" else "thumb"}"
    var image by remember(key) { mutableStateOf(ScreenImages.get(key)) }
    var failed by remember(key) { mutableStateOf(false) }
    LaunchedEffect(key) {
        if (image != null) return@LaunchedEffect
        val url = (if (full) screen.imageUrl else screen.thumbUrl ?: screen.imageUrl) ?: return@LaunchedEffect run { failed = true }
        image = vm.screenImage(url)?.also { ScreenImages.put(key, it) }
        failed = image == null
    }
    val ratio = if (screen.width > 0 && screen.height > 0) screen.width.toFloat() / screen.height else 16f / 9f
    Box(
        modifier.fillMaxWidth().aspectRatio(ratio).clip(RoundedCornerShape(Radius.field)).background(LocalEchoColors.current.surface2),
        contentAlignment = Alignment.Center
    ) {
        val shown = image
        when {
            shown != null -> Image(
                shown,
                contentDescription = screen.description ?: ScreenMoments.label(screen),
                contentScale = ContentScale.Fit,
                modifier = Modifier.fillMaxSize()
            )

            failed -> Text("The picture could not be loaded.", style = LocalEchoType.current.caption, color = Brand.Muted)

            else -> Spinner(18.dp)
        }
    }
}

/** A screen moment between the transcript lines: the picture, "Screen 7 · 14:32" and its one-line description. */
@Composable
internal fun ScreenMomentRow(vm: MainViewModel, screen: ScreenDto, onOpen: () -> Unit) {
    val type = LocalEchoType.current
    Column(
        Modifier.fillMaxWidth().padding(start = 36.dp + Space.s2, end = Space.s4, top = Space.s2, bottom = Space.s2)
            .clickable(role = Role.Button, onClickLabel = "Open the screen") { onOpen() }
            .semantics { contentDescription = "${ScreenMoments.label(screen)}${screen.description?.let { ". $it" } ?: ""}" },
        verticalArrangement = Arrangement.spacedBy(Space.s1)
    ) {
        ScreenPicture(vm, screen, full = false)
        Text(ScreenMoments.label(screen), style = type.labelM.tabular(), color = Brand.Muted)
        screen.description?.takeIf { it.isNotBlank() }?.let { Text(it, style = type.caption, color = Brand.Muted, maxLines = 2) }
    }
}

/** A screen on a project's Screens tab (since v1.5): the picture, "Screen 7 · 14:32" and the recording it is from. */
@Composable
internal fun ProjectScreenTile(vm: MainViewModel, screen: ProjectScreenDto, modifier: Modifier = Modifier, onOpen: () -> Unit) {
    val type = LocalEchoType.current
    val shown = screen.screen()
    Column(
        modifier.padding(vertical = Space.s2)
            .clickable(role = Role.Button, onClickLabel = "Open the screen") { onOpen() }
            .semantics { contentDescription = "${ScreenMoments.label(shown)}, ${screen.sessionTitle ?: "a recording"}" },
        verticalArrangement = Arrangement.spacedBy(Space.s1)
    ) {
        ScreenPicture(vm, shown, full = false)
        Text(ScreenMoments.label(shown), style = type.labelM.tabular(), color = Brand.Text)
        screen.sessionTitle?.takeIf { it.isNotBlank() }?.let { Text(it, style = type.caption, color = Brand.Muted, maxLines = 1) }
    }
}

/** The screen full size, with its description, the text read from it, and Play from here. */
@Composable
internal fun ScreenViewer(vm: MainViewModel, screen: ScreenDto, onPlay: (() -> Unit)?, onDismiss: () -> Unit) {
    val type = LocalEchoType.current
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Column(
            Modifier.fillMaxWidth().padding(Space.s3).clip(RoundedCornerShape(Radius.lg)).background(LocalEchoColors.current.surface)
                .verticalScroll(rememberScrollState()).padding(Space.s4),
            verticalArrangement = Arrangement.spacedBy(Space.s2)
        ) {
            Text(ScreenMoments.label(screen), style = type.titleS, color = Brand.Text)
            ScreenPicture(vm, screen, full = true)
            screen.description?.takeIf { it.isNotBlank() }?.let { Text(it, style = type.bodyM, color = Brand.Text) }
            screen.ocrText?.takeIf { it.isNotBlank() }?.let {
                Text("Text on the screen", style = type.labelM, color = Brand.Muted)
                Text(it, style = type.caption, color = Brand.Muted)
            }
            androidx.compose.foundation.layout.Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                if (onPlay != null) EchoButton("Play from here", onPlay, kind = ButtonKind.Secondary)
                EchoButton("Close", onDismiss, kind = ButtonKind.Text)
            }
        }
    }
}
