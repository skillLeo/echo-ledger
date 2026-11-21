package com.echoledger.mobile.ui.v2.components

import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.ui.v2.theme.EchoV2

/**
 * A menu row that opens a sub-menu (Export ▸): its icon, its words and ▸ on the end side, mirrored in right to left.
 * The same place on every platform (DESIGN_SYSTEM "Menus").
 */
@Composable
fun EchoSubMenuItem(icon: ImageVector, words: String, onClick: () -> Unit) {
    val tint = EchoV2.colors.textPrimary
    DropdownMenuItem(
        text = { Text(words, style = EchoV2.type.body, color = tint) },
        leadingIcon = { Icon(icon, null, tint = tint) },
        trailingIcon = { Icon(EchoIcons.Next, null, tint = EchoV2.colors.textSecondary) },
        onClick = onClick
    )
}

/** A sub-menu's first row: ‹ (mirrored in right to left) and the sub-menu's name; it goes back to the menu. */
@Composable
fun EchoSubMenuHeader(words: String, onBack: () -> Unit) {
    val back = EchoV2.strings.back
    DropdownMenuItem(
        text = { Text(words, style = EchoV2.type.captionStrong, color = EchoV2.colors.textSecondary) },
        leadingIcon = { Icon(EchoIcons.Previous, null, tint = EchoV2.colors.textSecondary) },
        onClick = onBack,
        modifier = Modifier.semantics { contentDescription = "$back, $words" }
    )
}
