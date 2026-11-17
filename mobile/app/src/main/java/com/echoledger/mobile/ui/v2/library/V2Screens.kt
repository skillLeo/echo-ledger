package com.echoledger.mobile.ui.v2.library

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.ui.ScreenImages
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSkeletonBlock
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.EchoTranscriptLine
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** Loads a screen's picture from its signed link (the view model's, or a test's); null when it can't be loaded. */
val LocalScreenPictures = staticCompositionLocalOf<suspend (String) -> ImageBitmap?> { { null } }

/** "Screen 7 · 14:32". */
@Composable
fun screenLabel(screen: ScreenDto): String = stringResource(R.string.v2_screens_label, screen.index, formatClock(screen.atMs))

/** "3 screens", for a Library row and the strip. */
@Composable
fun screensCount(count: Int): String =
    if (count == 1) stringResource(R.string.v2_screens_count_one) else stringResource(R.string.v2_screens_count_other, count)

/** A screen's picture at its own shape, loaded once; a grey block while it loads, a quiet line if it can't. */
@Composable
fun ScreenPicture(screen: ScreenDto, full: Boolean, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val load = LocalScreenPictures.current
    val key = "${screen.id}:${if (full) "full" else "thumb"}"
    var image by remember(key) { mutableStateOf(ScreenImages.get(key)) }
    var failed by remember(key) { mutableStateOf(false) }
    LaunchedEffect(key) {
        if (image != null) return@LaunchedEffect
        val url = (if (full) screen.imageUrl else screen.thumbUrl ?: screen.imageUrl)
        image = url?.let { load(it) }?.also { ScreenImages.put(key, it) }
        failed = image == null
    }
    val ratio = if (screen.width > 0 && screen.height > 0) screen.width.toFloat() / screen.height else 16f / 9f
    Box(
        modifier
            .fillMaxWidth()
            .aspectRatio(ratio)
            .clip(RoundedCornerShape(EchoTokens.Component.cardRadius))
            .background(c.bgSubtle),
        contentAlignment = Alignment.Center
    ) {
        val shown = image
        when {
            shown != null -> Image(shown, null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())

            failed -> Text(
                stringResource(R.string.v2_screens_picture_failed),
                style = EchoV2.type.caption,
                color = c.textSecondary,
                modifier = Modifier.padding(EchoTokens.Space.s8)
            )

            else -> EchoSkeletonBlock(Modifier.fillMaxSize(), height = EchoTokens.Space.s64)
        }
    }
}

/** One screen as a tile: its picture, "Screen 7 · 14:32" and the description's first line. */
@Composable
private fun ScreenTile(screen: ScreenDto, onOpen: () -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val label = screenLabel(screen)
    val open = stringResource(R.string.v2_screens_open)
    Column(
        modifier
            .clickable(role = Role.Button, onClickLabel = open, onClick = onOpen)
            .semantics(mergeDescendants = true) { contentDescription = listOfNotNull(label, screen.description).joinToString(". ") },
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        ScreenPicture(screen, full = false)
        Text(label, style = EchoV2.type.mono, color = c.textSecondary, maxLines = 1)
        screen.description?.takeIf { it.isNotBlank() }?.let {
            Text(
                it,
                style = EchoV2.type.content(it, EchoV2.type.caption),
                color = c.textPrimary,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis
            )
        }
    }
}

/** The recording page's Screens strip, under the title: every screen in time order, side by side. */
@Composable
fun ScreensStrip(screens: List<ScreenDto>, onOpen: (index: Int) -> Unit, modifier: Modifier = Modifier) {
    if (screens.isEmpty()) return
    val c = EchoV2.colors
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            Text(
                stringResource(R.string.v2_screens_title),
                style = EchoV2.type.label,
                color = c.textPrimary,
                modifier = Modifier.weight(1f)
            )
            Text(screensCount(screens.size), style = EchoV2.type.meta, color = c.textSecondary)
        }
        LazyRow(
            contentPadding = PaddingValues(horizontal = EchoTokens.Layout.pagePaddingMobile),
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            itemsIndexed(screens, key = { _, s -> s.id }) { i, s ->
                ScreenTile(s, onOpen = { onOpen(i) }, modifier = Modifier.width(EchoTokens.Space.s64 * STRIP_TILE_UNITS))
            }
        }
    }
}

/** A screen in the transcript is at most 4 × 64 dp = 256 dp wide. */
private const val INLINE_TILE_UNITS = 4f

/** A tile is 2.5 × 64 dp = 160 dp wide: two and a half show, so the strip reads as one that scrolls. */
private const val STRIP_TILE_UNITS = 2.5f

/** A screen between the transcript's lines, at its moment. */
@Composable
fun ScreenInline(screen: ScreenDto, onOpen: () -> Unit, modifier: Modifier = Modifier) {
    // Smaller than the strip's row is wide, so the transcript keeps reading as text.
    Box(modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)) {
        ScreenTile(screen, onOpen, Modifier.widthIn(max = EchoTokens.Space.s64 * INLINE_TILE_UNITS))
    }
}

/**
 * The screen viewer: the picture full width, "3 of 12", Play from here, its description, what was said in the 15 s
 * around it, the text read from it, and its file name; Previous and Next move through the recording's screens.
 */
@Composable
fun V2ScreenViewer(
    moments: List<ScreenMoment>,
    startIndex: Int,
    onPlayFrom: (atMs: Long) -> Unit,
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
    transcriptReady: Boolean = true
) {
    if (moments.isEmpty()) return
    val c = EchoV2.colors
    val text = v2Text
    var index by remember(moments) { mutableIntStateOf(startIndex.coerceIn(0, moments.lastIndex)) }
    val moment = moments[index]
    val screen = moment.screen
    BackHandler(onBack = onClose)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(title = screenLabel(screen), onBack = onClose) {
            Text(
                stringResource(R.string.v2_screens_position, index + 1, moments.size),
                style = EchoV2.type.meta,
                color = c.textSecondary,
                modifier = Modifier.padding(end = EchoTokens.Space.s16)
            )
        }
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = EchoTokens.Space.s24),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            val spoken = listOfNotNull(screenLabel(screen), screen.description).joinToString(". ")
            ScreenPicture(
                screen,
                full = true,
                modifier = Modifier
                    .padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                    .semantics { contentDescription = spoken }
            )
            EchoButton(
                stringResource(R.string.v2_screens_play_from_here),
                { onPlayFrom(screen.atMs) },
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                kind = EchoButtonKind.Secondary,
                icon = EchoIcons.Play
            )
            screen.description?.takeIf { it.isNotBlank() }?.let {
                Text(
                    it,
                    style = EchoV2.type.content(it, EchoV2.type.body),
                    color = c.textPrimary,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                )
            }
            EchoSectionHeader(stringResource(R.string.v2_screens_said))
            when {
                moment.said.isNotEmpty() -> Column {
                    moment.said.forEach { line ->
                        EchoTranscriptLine(
                            line.speaker,
                            line.atMs?.let { formatClock(it) }.orEmpty(),
                            line.text,
                            isMe = line.speaker == text.me
                        )
                    }
                }

                else -> Text(
                    stringResource(if (transcriptReady) R.string.v2_screens_said_none else R.string.v2_screens_said_loading),
                    style = EchoV2.type.meta,
                    color = c.textSecondary,
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                )
            }
            screen.ocrText?.takeIf { it.isNotBlank() }?.let {
                EchoSectionHeader(stringResource(R.string.v2_screens_text_on_screen))
                Text(
                    it,
                    style = EchoV2.type.content(it, EchoV2.type.meta),
                    color = c.textSecondary,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                )
            }
            // The file name only when the server gives one (its name in the meeting folder).
            moment.fileName?.let { name ->
                EchoSectionHeader(stringResource(R.string.v2_screens_file))
                Text(
                    name,
                    style = EchoV2.type.metaLatin,
                    color = c.textSecondary,
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                )
            }
        }
        if (moments.size > 1) {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s4),
                verticalAlignment = Alignment.CenterVertically
            ) {
                EchoIconButton(EchoIcons.Previous, stringResource(R.string.v2_screens_previous), { index-- }, enabled = index > 0)
                Box(Modifier.weight(1f))
                EchoIconButton(EchoIcons.Next, stringResource(R.string.v2_screens_next), { index++ }, enabled = index < moments.lastIndex)
            }
        }
    }
}

/** The screens count on a Library row: the screens icon and the number, read as "3 screens". */
@Composable
fun ScreensCountMark(count: Int, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val words = screensCount(count)
    Row(
        modifier.semantics(mergeDescendants = true) { contentDescription = words },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)
    ) {
        Icon(EchoIcons.Screens, null, Modifier.size(EchoTokens.Icon.sm), tint = c.textSecondary)
        Text(count.toString(), style = EchoV2.type.mono, color = c.textSecondary)
    }
}
