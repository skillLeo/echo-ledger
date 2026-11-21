package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.paneTitle
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.window.Dialog
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.PhoneDialogRadius
import com.echoledger.mobile.ui.v2.theme.ProvidePageLook
import com.echoledger.mobile.ui.v2.theme.rememberPageLook
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/**
 * A dialog that asks one question. [destructive] turns the confirm button red (delete); the dismiss button is quiet.
 * Back and a tap outside dismiss it.
 */
@Composable
fun EchoDialog(
    title: String,
    text: String,
    confirmLabel: String,
    onConfirm: () -> Unit,
    /** Null: only the confirm button (a notice with OK); Back and a tap outside still close it. */
    dismissLabel: String?,
    onDismiss: () -> Unit,
    destructive: Boolean = false
) {
    val look = rememberPageLook()
    Dialog(onDismissRequest = onDismiss) {
        // The dialog's window starts from the activity's: keep the page's language and text size.
        ProvidePageLook(look) {
            EchoDialogContent(title, text, confirmLabel, onConfirm, dismissLabel, onDismiss, destructive)
        }
    }
}

/** The dialog's surface, without the window (used by [EchoDialog] and in screenshots). */
@Composable
fun EchoDialogContent(
    title: String,
    text: String,
    confirmLabel: String,
    onConfirm: () -> Unit,
    dismissLabel: String?,
    onDismiss: () -> Unit,
    destructive: Boolean = false,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier.fillMaxWidth().semantics { paneTitle = title },
        shape = RoundedCornerShape(PhoneDialogRadius),
        color = c.bgOverlay,
        contentColor = c.textPrimary,
        shadowElevation = EchoTokens.Elevation.modal
    ) {
        Column(
            Modifier.padding(
                start = EchoTokens.Space.s24,
                end = EchoTokens.Space.s16,
                top = EchoTokens.Space.s24,
                bottom = EchoTokens.Space.s16
            ),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            Text(title, style = EchoV2.type.titleM, modifier = Modifier.padding(end = EchoTokens.Space.s8).semantics { heading() })
            Text(text, style = EchoV2.type.body, color = c.textSecondary, modifier = Modifier.padding(end = EchoTokens.Space.s8))
            FlowRow(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8, Alignment.End),
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
            ) {
                if (dismissLabel != null) EchoButton(dismissLabel, onDismiss, kind = EchoButtonKind.Quiet)
                EchoButton(confirmLabel, onConfirm, kind = if (destructive) EchoButtonKind.Danger else EchoButtonKind.Primary)
            }
        }
    }
}

/**
 * A bottom sheet for choices and short tasks (Share, Move). It has a drag handle, an optional [title] with a close
 * button, and dismisses on a swipe down, back, a tap above it, or the close button.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EchoBottomSheet(onDismiss: () -> Unit, title: String? = null, content: @Composable ColumnScope.() -> Unit) {
    val c = EchoV2.colors
    val state = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val scope = rememberCoroutineScope()
    val look = rememberPageLook()
    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = state,
        shape = RoundedCornerShape(topStart = EchoTokens.Component.sheetRadius, topEnd = EchoTokens.Component.sheetRadius),
        containerColor = c.bgOverlay,
        contentColor = c.textPrimary,
        dragHandle = { SheetHandle() }
    ) {
        // The sheet's window starts from the activity's: keep the page's language and text size.
        ProvidePageLook(look) {
            SheetBody(
                title = title,
                onClose = { scope.launch { state.hide() }.invokeOnCompletion { onDismiss() } },
                content = content
            )
        }
    }
}

/** The sheet's surface, without the window (screenshots). */
@Composable
fun EchoSheetContent(title: String?, onClose: () -> Unit, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(topStart = EchoTokens.Component.sheetRadius, topEnd = EchoTokens.Component.sheetRadius),
        color = c.bgOverlay,
        contentColor = c.textPrimary,
        shadowElevation = EchoTokens.Elevation.modal
    ) {
        Column {
            SheetHandle()
            SheetBody(title, onClose, content)
        }
    }
}

@Composable
private fun SheetHandle() {
    val description = EchoV2.strings.dragHandle
    Box(
        Modifier
            .fillMaxWidth()
            .padding(vertical = EchoTokens.Space.s8)
            .semantics { contentDescription = description },
        contentAlignment = Alignment.Center
    ) {
        Box(
            Modifier
                .size(EchoTokens.Component.sheetHandleWidth, EchoTokens.Component.sheetHandleHeight)
                .background(EchoV2.colors.borderStrong, RoundedCornerShape(EchoTokens.Component.chipRadius))
        )
    }
}

@Composable
private fun SheetBody(title: String?, onClose: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(bottom = EchoTokens.Space.s16)) {
        if (title != null) {
            Row(
                Modifier.fillMaxWidth().padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s4),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(title, style = EchoV2.type.titleS, modifier = Modifier.weight(1f).semantics { heading() })
                EchoIconButton(EchoIcons.Close, EchoV2.strings.close, onClick = onClose)
            }
        }
        content()
    }
}
