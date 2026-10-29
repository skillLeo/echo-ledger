package com.echoledger.mobile.ui.design

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.sizeIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.reduceMotion

/** One bottom-bar tab: outlined icon when inactive, filled when active; [badge] is a count (Activity). */
data class NavTab(val key: String, val label: String, val icon: ImageVector, val activeIcon: ImageVector, val badge: Int = 0)

/**
 * The bottom bar (64 dp + the system inset): two tabs, the record button raised in the middle, two tabs. Active = brand
 * with a filled icon; inactive = tertiary with an outlined one; no pill. Re-tapping the current tab calls [onReselect]
 * (scroll to top).
 */
@Composable
fun EchoBottomBar(
    tabs: List<NavTab>,
    current: String,
    onSelect: (String) -> Unit,
    onReselect: (String) -> Unit,
    recording: Boolean,
    onRecord: () -> Unit,
    onRecordLongPress: () -> Unit,
    modifier: Modifier = Modifier
) {
    require(tabs.size == 4) { "two tabs on each side of the record button" }
    val c = LocalEchoColors.current
    Box(modifier.fillMaxWidth()) {
        Column(Modifier.fillMaxWidth().background(c.surface).windowInsetsPadding(WindowInsets.navigationBars)) {
            HorizontalDivider(thickness = 1.dp, color = c.border)
            Row(Modifier.fillMaxWidth().height(64.dp), verticalAlignment = Alignment.CenterVertically) {
                tabs.take(2).forEach { tab -> TabSlot(tab, tab.key == current, onSelect, onReselect) }
                Spacer(Modifier.weight(1f))
                tabs.drop(2).forEach { tab -> TabSlot(tab, tab.key == current, onSelect, onReselect) }
            }
        }
        RecordButton(recording, onRecord, onRecordLongPress, Modifier.align(Alignment.TopCenter).offset(y = (-12).dp - 4.dp))
    }
}

@Composable
private fun androidx.compose.foundation.layout.RowScope.TabSlot(
    tab: NavTab,
    active: Boolean,
    onSelect: (String) -> Unit,
    onReselect: (String) -> Unit
) {
    val c = LocalEchoColors.current
    val tint = if (active) c.accentText else c.textFaint
    Column(
        Modifier
            .weight(1f)
            .height(64.dp)
            .combinedClickable(role = Role.Tab, onClick = { if (active) onReselect(tab.key) else onSelect(tab.key) })
            .semantics { selected = active },
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = androidx.compose.foundation.layout.Arrangement.Center
    ) {
        Box {
            Icon(if (active) tab.activeIcon else tab.icon, contentDescription = null, tint = tint, modifier = Modifier.size(24.dp))
            if (tab.badge > 0) {
                Box(
                    Modifier.align(Alignment.TopEnd).offset(x = 8.dp, y = (-4).dp).sizeIn(minWidth = 16.dp, minHeight = 16.dp)
                        .clip(CircleShape).background(c.dangerStrong).padding(horizontal = 4.dp),
                    contentAlignment = Alignment.Center
                ) {
                    Text(
                        if (tab.badge >
                            99
                        ) {
                            "99+"
                        } else {
                            tab.badge.toString()
                        },
                        style = LocalEchoType.current.micro.tabular(),
                        color = Color.White
                    )
                }
            }
        }
        Spacer(Modifier.height(4.dp))
        Text(tab.label, style = LocalEchoType.current.micro, color = tint, maxLines = 1)
    }
}

/**
 * The record button: a 56 dp brand circle with a 4 dp surface ring and a soft shadow, raised above the bar. Tap opens
 * the Record sheet; long-press starts a voice note at once. While recording it is red with a white stop square and a
 * soft pulsing ring.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun RecordButton(recording: Boolean, onClick: () -> Unit, onLongPress: () -> Unit, modifier: Modifier = Modifier) {
    val c = LocalEchoColors.current
    val haptics = LocalHapticFeedback.current
    val pulse = if (recording && !reduceMotion()) {
        val t = rememberInfiniteTransition(label = "pulse")
        t.animateFloat(1f, 1.18f, infiniteRepeatable(tween(1100), RepeatMode.Reverse), label = "ring").value
    } else {
        1f
    }
    val fill = if (recording) c.dangerStrong else c.accentStrong
    Box(modifier.size(64.dp), contentAlignment = Alignment.Center) {
        if (recording) {
            Box(
                Modifier.size(64.dp).graphicsLayer {
                    scaleX = pulse
                    scaleY = pulse
                    alpha = 2f - pulse * 1.4f
                }.clip(CircleShape).background(fill.copy(alpha = 0.25f))
            )
        }
        Box(
            Modifier
                .size(64.dp)
                .shadow(
                    if (c.isDark) 0.dp else 8.dp,
                    CircleShape,
                    ambientColor = Color.Black.copy(alpha = 0.1f),
                    spotColor = Color.Black.copy(alpha = 0.1f)
                )
                .clip(CircleShape)
                .background(c.surface)
                .then(if (c.isDark) Modifier.border(1.dp, c.borderStrong, CircleShape) else Modifier)
                .padding(4.dp)
                .clip(CircleShape)
                .background(fill)
                .combinedClickable(
                    interactionSource = remember { MutableInteractionSource() },
                    indication = null,
                    role = Role.Button,
                    onClick = onClick,
                    onLongClick = {
                        haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                        onLongPress()
                    }
                )
                .semantics { contentDescription = if (recording) "Stop recording" else "Record" },
            contentAlignment = Alignment.Center
        ) {
            if (recording) {
                Box(Modifier.size(18.dp).clip(RoundedCornerShape(4.dp)).background(Color.White))
            } else {
                Icon(Icons.Rounded.Mic, contentDescription = null, tint = Color.White, modifier = Modifier.size(28.dp))
            }
        }
    }
}

/** The slim "Recording 02:14 ■" bar shown above the bottom bar while recording anywhere; tap opens the Live view. */
@Composable
fun RecordingStrip(elapsed: String, onOpen: () -> Unit, onStop: () -> Unit, modifier: Modifier = Modifier) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    Row(
        modifier.fillMaxWidth().height(
            40.dp
        ).background(c.dangerSoft).combinedClickable(role = Role.Button, onClick = onOpen).padding(start = 16.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(Modifier.size(8.dp).clip(CircleShape).background(c.dangerStrong))
        Spacer(Modifier.size(8.dp))
        Text("Recording", style = type.labelM, color = c.text)
        Spacer(Modifier.size(6.dp))
        Text(elapsed, style = type.labelM.tabular(), color = c.text, modifier = Modifier.weight(1f))
        Box(
            Modifier.size(48.dp, 40.dp).combinedClickable(role = Role.Button, onClick = onStop).semantics {
                contentDescription =
                    "Stop recording"
            },
            contentAlignment = Alignment.Center
        ) {
            Box(Modifier.size(12.dp).clip(RoundedCornerShape(2.dp)).background(c.danger))
        }
    }
}
