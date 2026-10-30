package com.echoledger.mobile.ui.design

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.ui.Brand
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Motion
import com.echoledger.mobile.ui.Radius
import com.echoledger.mobile.ui.Space
import com.echoledger.mobile.ui.reduceMotion

/** Press feedback shared by every tappable surface: scales to 0.96 while held (none when animations are off). */
@Composable
fun Modifier.pressScale(interaction: MutableInteractionSource): Modifier {
    val pressed by interaction.collectIsPressedAsState()
    val target = if (pressed && !reduceMotion()) 0.96f else 1f
    val scale by animateFloatAsState(target, tween(Motion.PRESS, easing = Motion.Standard), label = "press")
    return this.scale(scale)
}

// ---- Buttons ------------------------------------------------------------------------------------------------

enum class ButtonKind { Primary, Secondary, Text, Destructive }

/** The one button: 48 dp, full radius, label-l. Primary is indigo (one per screen); destructive is red. */
@Composable
fun EchoButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    kind: ButtonKind = ButtonKind.Primary,
    icon: ImageVector? = null,
    enabled: Boolean = true,
    loading: Boolean = false
) {
    val c = LocalEchoColors.current
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val (fill, content) = when (kind) {
        ButtonKind.Primary -> (if (pressed) c.accentPressed else c.accentStrong) to c.onAccent
        ButtonKind.Secondary -> (if (pressed) c.surface3 else c.surface2) to c.text
        ButtonKind.Text -> (if (pressed) c.surface2 else Color.Transparent) to c.accentText
        ButtonKind.Destructive -> c.dangerStrong to Color.White
    }
    Row(
        modifier
            .pressScale(interaction)
            .heightIn(min = 48.dp)
            .clip(CircleShape)
            .background(if (enabled) fill else c.surface2)
            .combinedClickable(interaction, indication = null, enabled = enabled && !loading, role = Role.Button, onClick = onClick)
            .padding(horizontal = if (kind == ButtonKind.Text) Space.s3 else Space.s5),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically
    ) {
        val tint = if (enabled) content else c.textFaint
        if (loading) {
            Spinner(16.dp, tint)
            Spacer(Modifier.width(Space.s2))
        } else if (icon != null) {
            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(Space.s2))
        }
        Text(text, style = LocalEchoType.current.labelL, color = tint, maxLines = 1)
    }
}

/** A small indeterminate ring (processing); stands still when animations are off. */
@Composable
fun Spinner(size: Dp = 16.dp, color: Color = Brand.AccentText) {
    val still = reduceMotion()
    val angle = if (still) {
        0f
    } else {
        val t = rememberInfiniteTransition(label = "spinner")
        t.animateFloat(0f, 360f, infiniteRepeatable(tween(900, easing = LinearEasing)), label = "angle").value
    }
    androidx.compose.foundation.Canvas(Modifier.size(size).graphicsLayer { rotationZ = angle }) {
        val stroke = this.size.minDimension / 8f
        drawArc(
            color,
            startAngle = 0f,
            sweepAngle = 270f,
            useCenter = false,
            topLeft = androidx.compose.ui.geometry.Offset(stroke / 2, stroke / 2),
            size = androidx.compose.ui.geometry.Size(this.size.width - stroke, this.size.height - stroke),
            style = androidx.compose.ui.graphics.drawscope.Stroke(stroke, cap = androidx.compose.ui.graphics.StrokeCap.Round)
        )
    }
}

// ---- Bars and headers ---------------------------------------------------------------------------------------

/** Top bar: a title-l title (or title-m on detail pages), an optional back button and at most a few quiet icons. */
@Composable
fun EchoTopBar(
    title: String,
    modifier: Modifier = Modifier,
    navigation: (@Composable () -> Unit)? = null,
    large: Boolean = true,
    actions: @Composable RowScope.() -> Unit = {}
) {
    val type = LocalEchoType.current
    Row(
        modifier.fillMaxWidth().height(64.dp).padding(start = if (navigation == null) Space.screen else Space.s1, end = Space.s1),
        verticalAlignment = Alignment.CenterVertically
    ) {
        navigation?.invoke()
        Text(
            title,
            style = if (large) type.titleL else type.titleM,
            color = Brand.Text,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f).padding(start = if (navigation == null) 0.dp else Space.s1)
        )
        actions()
    }
}

/** A 48 dp icon button in bar grey (24 icon); [label] is what TalkBack reads. */
@Composable
fun BarIcon(icon: ImageVector, label: String, onClick: () -> Unit, tint: Color = Brand.Muted) {
    IconButton(onClick = onClick, modifier = Modifier.size(48.dp)) {
        Icon(icon, contentDescription = label, tint = tint, modifier = Modifier.size(24.dp))
    }
}

/** Section title in a list ("Today", "Waiting for you"): label-m semibold, secondary. */
@Composable
fun SectionHeader(text: String, modifier: Modifier = Modifier, trailing: (@Composable () -> Unit)? = null) {
    Row(
        modifier.fillMaxWidth().background(Brand.Background).padding(horizontal = Space.screen).padding(top = Space.s4, bottom = Space.s2),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            text,
            style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.SemiBold),
            color = Brand.Muted,
            modifier = Modifier.weight(1f)
        )
        trailing?.invoke()
    }
}

/** The search entry: a 48 dp pill on surface-muted; tapping it opens the full search. */
@Composable
fun SearchPill(placeholder: String, onClick: () -> Unit, modifier: Modifier = Modifier, trailing: (@Composable () -> Unit)? = null) {
    val interaction = remember { MutableInteractionSource() }
    Row(
        modifier
            .fillMaxWidth()
            .height(48.dp)
            .clip(CircleShape)
            .background(Brand.SurfaceAlt)
            .combinedClickable(interaction, indication = null, role = Role.Button, onClick = onClick)
            .padding(start = Space.s4, end = Space.s1),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(Icons.Rounded.Search, contentDescription = null, tint = Brand.Muted, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(Space.s3))
        Text(
            placeholder,
            style = LocalEchoType.current.bodyM,
            color = Brand.Muted,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f)
        )
        trailing?.invoke()
    }
}

/** Filter chip: 32 dp, radius 10; active = brand subtle with brand text and the number of choices. */
@Composable
fun EchoFilterChip(label: String, active: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier, count: Int = 0) {
    val c = LocalEchoColors.current
    val shape = RoundedCornerShape(Radius.sm)
    Row(
        modifier
            .height(32.dp)
            .clip(shape)
            .background(if (active) c.accentSoft else Color.Transparent)
            .border(BorderStroke(1.dp, if (active) Color.Transparent else c.borderStrong), shape)
            .combinedClickable(role = Role.Checkbox, onClick = onClick)
            .semantics { selected = active }
            .padding(horizontal = Space.s3),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            if (active &&
                count > 0
            ) {
                "$label · $count"
            } else {
                label
            },
            style = LocalEchoType.current.labelM,
            color = if (active) c.accentText else c.textMuted,
            maxLines = 1
        )
    }
}

// ---- Rows ----------------------------------------------------------------------------------------------------

/** A tile holding a source icon: 44 dp, radius 12, on a 12% tint of [tint]. */
@Composable
fun IconTile(icon: ImageVector, tint: Color, modifier: Modifier = Modifier, size: Dp = 44.dp) {
    Box(
        modifier.size(size).clip(RoundedCornerShape(Radius.field)).background(tint.copy(alpha = 0.12f)),
        contentAlignment = Alignment.Center
    ) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(size * 0.5f))
    }
}

/**
 * One recording in a list (72 dp): tile, one-line title with the time at the end, a meta line of only the parts that
 * exist, and a status only when it needs attention. In selection the tile turns into a check circle and the row gets
 * the brand-subtle background. Unopened recordings have a semibold title and a brand dot.
 */
@Composable
fun RecordingRow(
    title: String,
    time: String,
    meta: String,
    tile: @Composable () -> Unit,
    onClick: () -> Unit,
    onLongClick: () -> Unit,
    modifier: Modifier = Modifier,
    onTileClick: () -> Unit = onLongClick,
    selected: Boolean = false,
    selecting: Boolean = false,
    unopened: Boolean = false,
    pinned: Boolean = false,
    metaColor: Color = Brand.Muted,
    status: (@Composable () -> Unit)? = null,
    badge: (@Composable () -> Unit)? = null,
    stateLabel: String? = null,
    divider: Boolean = true
) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    val haptics = LocalHapticFeedback.current
    Column(modifier.fillMaxWidth().background(if (selected) c.accentSoft else Color.Transparent)) {
        Row(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 72.dp)
                .combinedClickable(
                    onClick = onClick,
                    onLongClick = {
                        haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                        onLongClick()
                    }
                )
                .semantics {
                    this.selected = selected
                    stateLabel?.let { stateDescription = it }
                }
                .padding(horizontal = Space.screen, vertical = Space.s3),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Box(Modifier.size(44.dp).combinedClickable(onClick = onTileClick, role = Role.Checkbox)) {
                AnimatedContent(
                    targetState = selecting && selected,
                    transitionSpec = { fadeIn(tween(Motion.BASE)) togetherWith fadeOut(tween(Motion.BASE)) },
                    label = "tile"
                ) { checked ->
                    if (checked) {
                        Box(Modifier.size(44.dp).clip(CircleShape).background(c.accentStrong), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Check, contentDescription = null, tint = c.onAccent, modifier = Modifier.size(24.dp))
                        }
                    } else {
                        Box { tile() }
                    }
                }
                if (badge != null && !(selecting && selected)) Box(Modifier.align(Alignment.BottomEnd)) { badge() }
            }
            Spacer(Modifier.width(Space.s3))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
                        if (unopened) {
                            Box(Modifier.size(6.dp).clip(CircleShape).background(c.accentStrong))
                            Spacer(Modifier.width(Space.s1 + Space.s0))
                        }
                        Text(
                            title,
                            style = type.bodyL.copy(fontWeight = if (unopened) FontWeight.SemiBold else FontWeight.Medium),
                            color = c.text,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f, fill = false)
                        )
                        if (pinned) {
                            Spacer(Modifier.width(Space.s1))
                            Icon(Icons.Rounded.PushPin, contentDescription = "Pinned", tint = c.textFaint, modifier = Modifier.size(14.dp))
                        }
                    }
                    Spacer(Modifier.width(Space.s2))
                    Text(time, style = type.caption.tabular(), color = c.textFaint, maxLines = 1)
                }
                Spacer(Modifier.height(Space.s0))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (status != null) {
                        status()
                        Spacer(Modifier.width(Space.s1 + Space.s0))
                    }
                    Text(
                        meta,
                        style = type.labelM.copy(fontWeight = FontWeight.Normal),
                        color = metaColor,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
            }
        }
        if (divider) HorizontalDivider(Modifier.padding(start = Space.screen + 44.dp + Space.s3), thickness = 1.dp, color = c.border)
    }
}

/**
 * A settings row: 32 dp tinted icon tile, title, a short summary (two lines at most) and a trailing switch, chevron or
 * value; the whole row is the touch target.
 */
@Composable
fun SettingsRow(
    icon: ImageVector,
    title: String,
    modifier: Modifier = Modifier,
    summary: String? = null,
    tint: Color = Brand.AccentText,
    onClick: (() -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null
) {
    val type = LocalEchoType.current
    Row(
        modifier
            .fillMaxWidth()
            .heightIn(min = 56.dp)
            .then(if (onClick != null) Modifier.combinedClickable(onClick = onClick) else Modifier)
            .padding(horizontal = Space.screen, vertical = Space.s3),
        verticalAlignment = Alignment.CenterVertically
    ) {
        IconTile(icon, tint, size = 32.dp)
        Spacer(Modifier.width(Space.s3))
        Column(Modifier.weight(1f)) {
            Text(title, style = type.bodyL, color = Brand.Text)
            if (summary != null) {
                Text(
                    summary,
                    style = type.labelM.copy(fontWeight = FontWeight.Normal),
                    color = Brand.Muted,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis
                )
            }
        }
        trailing?.let {
            Spacer(Modifier.width(Space.s2))
            it()
        }
    }
}

/** Selection mode replaces the top bar: ✕ · "3 selected" · actions. Back also leaves selection (the caller handles it). */
@Composable
fun SelectionBar(count: Int, onClose: () -> Unit, modifier: Modifier = Modifier, actions: @Composable RowScope.() -> Unit) {
    Row(
        modifier.fillMaxWidth().height(64.dp).background(Brand.Background).padding(horizontal = Space.s1),
        verticalAlignment = Alignment.CenterVertically
    ) {
        BarIcon(Icons.Rounded.Close, "Leave selection", onClose, tint = Brand.Text)
        Text(
            "$count selected",
            style = LocalEchoType.current.titleS,
            color = Brand.Text,
            modifier = Modifier.weight(1f).padding(start = Space.s1)
        )
        actions()
    }
}

// ---- States --------------------------------------------------------------------------------------------------

/** Empty, no-results and offline states: a 40 dp icon in a 72 dp circle, a title, one line and at most one button. */
@Composable
fun EchoEmptyState(
    icon: ImageVector,
    title: String,
    modifier: Modifier = Modifier,
    body: String? = null,
    action: String? = null,
    onAction: () -> Unit = {}
) {
    val type = LocalEchoType.current
    Column(
        modifier.fillMaxWidth().padding(horizontal = Space.s6, vertical = Space.s7),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Box(Modifier.size(72.dp).clip(CircleShape).background(Brand.SurfaceAlt), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = Brand.Muted, modifier = Modifier.size(40.dp))
        }
        Spacer(Modifier.height(Space.s4))
        Text(title, style = type.titleS, color = Brand.Text)
        if (body != null) {
            Spacer(Modifier.height(Space.s1))
            Text(body, style = type.bodyM, color = Brand.Muted, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        }
        if (action != null) {
            Spacer(Modifier.height(Space.s5))
            EchoButton(action, onAction, kind = ButtonKind.Secondary)
        }
    }
}

/** A loading placeholder shaped like a [RecordingRow]; the shimmer runs every 1.2 s (still when animations are off). */
@Composable
fun SkeletonRow(modifier: Modifier = Modifier) {
    val c = LocalEchoColors.current
    val alpha = if (reduceMotion()) {
        1f
    } else {
        val t = rememberInfiniteTransition(label = "skeleton")
        t.animateFloat(0.55f, 1f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "alpha").value
    }
    Row(
        modifier.fillMaxWidth().height(72.dp).padding(horizontal = Space.screen).graphicsLayer {
            this.alpha = alpha
        },
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(Modifier.size(44.dp).clip(RoundedCornerShape(Radius.field)).background(c.surface2))
        Spacer(Modifier.width(Space.s3))
        Column(Modifier.weight(1f)) {
            Box(Modifier.fillMaxWidth(0.6f).height(14.dp).clip(RoundedCornerShape(Radius.tag)).background(c.surface2))
            Spacer(Modifier.height(Space.s2))
            Box(Modifier.fillMaxWidth(0.4f).height(12.dp).clip(RoundedCornerShape(Radius.tag)).background(c.surface2))
        }
    }
}

enum class BannerKind { Info, Warning, Error }

/** One compact inline message with an action and an optional dismiss: info, warning or error tint. */
@Composable
fun InlineBanner(
    text: String,
    modifier: Modifier = Modifier,
    kind: BannerKind = BannerKind.Info,
    action: String? = null,
    onAction: () -> Unit = {},
    onDismiss: (() -> Unit)? = null
) {
    val c = LocalEchoColors.current
    val (bg, fg, icon) = when (kind) {
        BannerKind.Info -> Triple(c.accentSoft, c.accentText, Icons.Rounded.Info)
        BannerKind.Warning -> Triple(c.warningFill.copy(alpha = if (c.isDark) 0.14f else 0.18f), c.warning, Icons.Rounded.WarningAmber)
        BannerKind.Error -> Triple(c.dangerSoft, c.danger, Icons.Rounded.ErrorOutline)
    }
    Row(
        modifier.fillMaxWidth().clip(
            RoundedCornerShape(Radius.md)
        ).background(bg).padding(start = Space.s3, top = Space.s1, bottom = Space.s1),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(icon, contentDescription = null, tint = fg, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(Space.s2))
        Text(
            text,
            style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.Normal),
            color = c.text,
            modifier = Modifier.weight(1f).padding(vertical = Space.s2)
        )
        if (action != null) EchoButton(action, onAction, kind = ButtonKind.Text)
        if (onDismiss != null) BarIcon(Icons.Rounded.Close, "Dismiss", onDismiss)
        if (action == null && onDismiss == null) Spacer(Modifier.width(Space.s3))
    }
}

/** The undo message after a delete or discard: dark, radius 12, 5 s (the caller times it). */
@Composable
fun UndoSnackbar(text: String, onUndo: () -> Unit, modifier: Modifier = Modifier) {
    Row(
        modifier
            .fillMaxWidth()
            .padding(horizontal = Space.screen)
            .clip(RoundedCornerShape(Radius.field))
            .background(Color(0xFF1C2024))
            .padding(start = Space.s4),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(
            text,
            style = LocalEchoType.current.bodyM,
            color = Color(0xFFEDEEF0),
            modifier = Modifier.weight(1f).padding(vertical = Space.s3)
        )
        Text(
            "Undo",
            style = LocalEchoType.current.labelL,
            color = Color(0xFF9EB1FF),
            modifier = Modifier.heightIn(
                min = 48.dp
            ).combinedClickable(role = Role.Button, onClick = onUndo).padding(horizontal = Space.s4, vertical = 14.dp)
        )
    }
}

// ---- People --------------------------------------------------------------------------------------------------

/** Eight soft avatar colours (background, initials) per theme; a name always gets the same one. */
private val AvatarLight = listOf(
    0xFFE1E9FF to 0xFF3A5BC7,
    0xFFDDF3E4 to 0xFF218358,
    0xFFFFE9C2 to 0xFFAB6400,
    0xFFFEE7E9 to 0xFFCE2C31,
    0xFFE0F4F7 to 0xFF0D74A7,
    0xFFF3E7FC to 0xFF8145B5,
    0xFFE5F6EF to 0xFF008573,
    0xFFF0F0F3 to 0xFF60646C
)
private val AvatarDark = listOf(
    0xFF1D2E62 to 0xFF9EB1FF,
    0xFF132D21 to 0xFF3DD68C,
    0xFF302008 to 0xFFFFCA16,
    0xFF3B1219 to 0xFFFF9592,
    0xFF0F2A3D to 0xFF70B8FF,
    0xFF301C3B to 0xFFD19DFF,
    0xFF0D2D2A to 0xFF0BD8B6,
    0xFF272A2D to 0xFFB0B4BA
)

/** Initials of [name] on its own soft colour ("Omar Khan" → "OK"). */
@Composable
fun Avatar(name: String, modifier: Modifier = Modifier, size: Dp = 32.dp) {
    val palette = if (LocalEchoColors.current.isDark) AvatarDark else AvatarLight
    val (bg, fg) = palette[Math.floorMod(name.trim().lowercase().hashCode(), palette.size)]
    val initials = name.trim().split(Regex("\\s+")).filter {
        it.isNotEmpty()
    }.take(2).joinToString("") { it.take(1).uppercase() }.ifEmpty { "?" }
    Box(
        modifier.size(size).clip(CircleShape).background(Color(bg)).semantics {
            contentDescription = name
        },
        contentAlignment = Alignment.Center
    ) {
        Text(initials, style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.SemiBold), color = Color(fg))
    }
}

// ---- Choice --------------------------------------------------------------------------------------------------

/** 2-4 choices side by side (40 dp): the chosen one sits on a raised surface. */
@Composable
fun SegmentedControl(options: List<String>, selected: Int, onSelect: (Int) -> Unit, modifier: Modifier = Modifier) {
    val c = LocalEchoColors.current
    Row(modifier.fillMaxWidth().height(40.dp).clip(CircleShape).background(c.surface2).padding(Space.s0 + Space.s0 / 2)) {
        options.forEachIndexed { index, label ->
            val on = index == selected
            Box(
                Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .height(34.dp)
                    .clip(CircleShape)
                    .background(if (on) c.surface else Color.Transparent)
                    .then(if (on && !c.isDark) Modifier.border(1.dp, c.border, CircleShape) else Modifier)
                    .combinedClickable(role = Role.Tab, onClick = { onSelect(index) })
                    .semantics { this.selected = on },
                contentAlignment = Alignment.Center
            ) {
                Text(label, style = LocalEchoType.current.labelM, color = if (on) c.text else c.textMuted, maxLines = 1)
            }
        }
    }
}

/** Default content padding of lists on a screen (side margin and room above the bottom bar). */
val ListPadding = PaddingValues(bottom = 96.dp)

@OptIn(ExperimentalFoundationApi::class)
private fun Modifier.combinedClickable(
    interaction: MutableInteractionSource,
    indication: Nothing?,
    enabled: Boolean = true,
    role: Role? = null,
    onClick: () -> Unit
): Modifier =
    this.combinedClickable(interactionSource = interaction, indication = indication, enabled = enabled, role = role, onClick = onClick)
