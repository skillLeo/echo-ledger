package com.echoledger.mobile.ui.v2

import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
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

    /**
     * Walks [steps] in order, running each one and collecting after it: each control ("kind: name") with the first step
     * it was seen in. A control seen only later, or already earlier, than its mapped step is a different page.
     */
    fun <S> firstSeen(compose: ComposeContentTestRule, steps: List<S>, run: (S) -> Unit): Map<String, S> {
        val first = linkedMapOf<String, S>()
        for (step in steps) {
            run(step)
            val now = mutableSetOf<String>()
            collect(compose, now)
            now.forEach { first.putIfAbsent(it, step) }
        }
        return first
    }

    /**
     * The parity check: every control today's page shows is mapped (to the v2 control, "kind: name", or to a reason in
     * [reasons]), nothing mapped is missing from today's page, and each mapped pair is first seen in the same step on
     * both pages. Returns the problems, empty when the pages match.
     */
    fun <S> problems(today: Map<String, S>, v2: Map<String, S>, where: Map<String, String>, reasons: Map<String, String>): List<String> {
        val out = mutableListOf<String>()
        today.keys.filter { it !in where && it !in reasons }.sorted().forEach { out += "unmapped today: $it" }
        (where.keys + reasons.keys).filter { it !in today }.sorted().forEach { out += "mapped but not on today's page: $it" }
        for ((old, new) in where) {
            val step = today[old] ?: continue
            val seen = v2[new]
            if (seen == null) {
                out += "missing on v2: $new (for $old, step $step)"
            } else if (seen != step) {
                out += "on v2 first in step $seen, today in step $step: $new (for $old)"
            }
        }
        return out
    }
}
