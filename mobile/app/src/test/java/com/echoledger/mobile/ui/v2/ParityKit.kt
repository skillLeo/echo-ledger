package com.echoledger.mobile.ui.v2

import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isRoot
import androidx.compose.ui.test.junit4.ComposeContentTestRule

/**
 * Render-and-collect for parity tests: every control on screen (dialogs and menus included) as "kind: name", where kind
 * is textbox, switch, radio or button and name is what it says it is for screen readers, else its first words (a field's
 * label), else its icon's; a switch with no words of its own is named by the words just before it.
 */
object ParityKit {
    fun collect(compose: ComposeContentTestRule, into: MutableSet<String>) {
        compose.waitForIdle()
        for (root in compose.onAllNodes(isRoot(), useUnmergedTree = true).fetchSemanticsNodes()) visit(root, into)
    }

    private fun visit(node: SemanticsNode, into: MutableSet<String>) {
        val c = node.config
        val role = c.getOrNull(SemanticsProperties.Role)?.toString()
        val kind = when {
            c.getOrNull(SemanticsActions.SetText) != null -> "textbox"
            role == "RadioButton" || (c.getOrNull(SemanticsProperties.Selected) != null && role != "Tab") -> "radio"
            c.getOrNull(SemanticsProperties.ToggleableState) != null -> "switch"
            c.getOrNull(SemanticsActions.OnClick) != null -> "button"
            else -> null
        }
        val named = name(node) ?: node.parent?.children?.let { siblings ->
            siblings.take(siblings.indexOfFirst { it.id == node.id }).lastOrNull { it.config.contains(SemanticsProperties.Text) }
                ?.config?.get(SemanticsProperties.Text)?.firstOrNull()?.text
        }
        if (kind != null) named?.let { into += "$kind: $it" }
        node.children.forEach { visit(it, into) }
    }

    fun name(node: SemanticsNode): String? = node.config.getOrNull(SemanticsProperties.ContentDescription)?.firstOrNull()
        ?: firstIn(node) { it.config.getOrNull(SemanticsProperties.Text)?.firstOrNull()?.text }
        ?: firstIn(node) { it.config.getOrNull(SemanticsProperties.ContentDescription)?.firstOrNull() }

    private fun firstIn(node: SemanticsNode, pick: (SemanticsNode) -> String?): String? =
        pick(node) ?: node.children.firstNotNullOfOrNull { firstIn(it, pick) }

    /** Is a control with this name on screen (its words, its screen-reader name or its click label)? */
    fun has(compose: ComposeContentTestRule, name: String): Boolean =
        compose.onAllNodes(hasText(name) or hasContentDescription(name), useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()
}
