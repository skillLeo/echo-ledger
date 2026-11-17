package com.echoledger.mobile.ui.v2.workspace

import com.echoledger.mobile.R
import com.echoledger.mobile.data.BriefSections
import com.echoledger.mobile.data.MemoryItemDto
import java.util.Locale

/** The tabs of a project's page, in order (desktop's WorkspaceV2 and docs/v2/IA.md 2.2). */
enum class WorkspaceTab(val label: Int) {
    Recordings(R.string.v2_ws_tab_recordings),
    Files(R.string.v2_ws_tab_files),
    Ask(R.string.v2_ws_tab_ask),
    Memory(R.string.v2_ws_tab_memory),
    Notes(R.string.v2_ws_tab_notes),
    Screens(R.string.v2_ws_tab_screens);

    companion object {
        /** Ask only when the plan has AI notes, Call notes only while the project's AI notes are on (as on the desktop). */
        fun shown(aiNotesInPlan: Boolean, aiNotesOn: Boolean): List<WorkspaceTab> =
            entries.filter { (it != Ask || aiNotesInPlan) && (it != Notes || aiNotesOn) }
    }
}

/** A memory line's kind: what the Kind picker says, and the heading its lines go under. */
enum class MemoryKind(val wire: String, val label: Int, val heading: Int) {
    Decision("decision", R.string.v2_ws_k_decision, R.string.v2_ws_h_decision),
    Scope("scope", R.string.v2_ws_k_scope, R.string.v2_ws_h_scope),
    Change("change", R.string.v2_ws_k_change, R.string.v2_ws_h_change),
    Question("question", R.string.v2_ws_k_question, R.string.v2_ws_h_question)
}

/** The brief's fields, in the order the page and the editor show them. */
enum class BriefField(val label: Int) {
    Goal(R.string.v2_ws_f_goal),
    Scope(R.string.v2_ws_f_scope),
    Constraints(R.string.v2_ws_f_constraints),
    Deadlines(R.string.v2_ws_f_deadlines),
    Budget(R.string.v2_ws_f_budget),
    People(R.string.v2_ws_f_people),
    Glossary(R.string.v2_ws_f_glossary)
}

/** The project page's rules, pure (no Android or Compose state). */
object WorkspaceModel {
    /** Memory lines under their kind, pinned first, kinds in [MemoryKind] order; kinds with no lines are left out. */
    fun memoryGroups(lines: List<MemoryItemDto>): List<Pair<MemoryKind, List<MemoryItemDto>>> = MemoryKind.entries.map { kind ->
        kind to lines.filter { it.kind == kind.wire }.sortedByDescending { it.pinned }
    }.filter { it.second.isNotEmpty() }

    /** What a brief field holds, as the page shows it (lists one per line); empty when it holds nothing. */
    fun value(sections: BriefSections, field: BriefField): List<String> = when (field) {
        BriefField.Goal -> listOf(sections.goal).filter { it.isNotBlank() }
        BriefField.Scope -> sections.scope
        BriefField.Constraints -> sections.constraints
        BriefField.Deadlines -> sections.deadlines
        BriefField.Budget -> sections.budget
        BriefField.People -> sections.people
        BriefField.Glossary -> sections.glossary
    }

    /** The editor's text for each field: the goal as it is, lists one per line. */
    fun editing(sections: BriefSections): Map<BriefField, String> =
        BriefField.entries.associateWith { value(sections, it).joinToString("\n") }

    /** The editor's text back into a brief: lines trimmed, bullets and empty lines dropped. */
    fun sections(editing: Map<BriefField, String>): BriefSections {
        fun lines(field: BriefField) = editing[field].orEmpty().lines()
            .map { it.trim().removePrefix("• ").removePrefix("- ").trim() }
            .filter { it.isNotEmpty() }
        return BriefSections(
            goal = editing[BriefField.Goal].orEmpty().trim(),
            scope = lines(BriefField.Scope),
            constraints = lines(BriefField.Constraints),
            deadlines = lines(BriefField.Deadlines),
            budget = lines(BriefField.Budget),
            people = lines(BriefField.People),
            glossary = lines(BriefField.Glossary)
        )
    }

    /** "1.20": an amount of US dollars with two decimals, the same in every language. */
    fun dollars(amount: Double): String = String.format(Locale.ROOT, "%.2f", amount)

    /** The kinds a filing rule can have, as the server names them, with the words for the picker. */
    val RULE_KINDS = listOf(
        "title" to R.string.v2_ws_rule_title,
        "contact" to R.string.v2_ws_rule_contact,
        "app" to R.string.v2_ws_rule_app
    )

    fun ruleHint(kind: String): Int = when (kind) {
        "contact" -> R.string.v2_ws_rule_hint_contact
        "app" -> R.string.v2_ws_rule_hint_app
        else -> R.string.v2_ws_rule_hint_title
    }

    /** While the brief, notes or a file are being made on the server, the page looks again. */
    fun working(briefStatus: String, noteStatuses: List<String>, fileStatuses: List<String>): Boolean =
        briefStatus == "working" || "pending" in noteStatuses || "extracting" in fileStatuses
}
