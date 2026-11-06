package com.echoledger.mobile.core

import com.echoledger.mobile.data.ConfigWorkspaceDto
import com.echoledger.mobile.data.PlanDto
import kotlinx.serialization.Serializable

/**
 * What the workspace's plan allows and this person's role there (since v1.5, docs/API.md "Plans and features" and
 * "Workspaces, roles and privacy"), kept on this phone from the server's config. An older server sends nothing:
 * everything on, no limits, role unknown (a member).
 */
@Serializable
data class PlanState(
    val name: String? = null,
    val liveTranslate: Boolean = true,
    val aiNotes: Boolean = true,
    val workspace: String? = null,
    val role: String? = null,
    val monthlyMinutes: Long? = null,
    val usedMinutes: Long? = null
) {
    /** A viewer opens what is shared with them and records nothing. */
    val viewer: Boolean get() = role == "viewer"

    /** "Free plan · 120 of 300 min this month", "Personal plan", or null before the server said. */
    fun summary(): String? {
        val plan = name?.takeIf { it.isNotBlank() }?.let { "$it plan" } ?: return null
        val limit = monthlyMinutes ?: return plan
        return "$plan · ${usedMinutes ?: 0} of $limit min this month"
    }

    /** "Acme team · Viewer", or null when the server did not say. */
    fun membership(): String? = listOfNotNull(workspace?.takeIf { it.isNotBlank() }, roleLabel(role)).joinToString(" · ").ifEmpty { null }

    companion object {
        const val NO_LIVE_TRANSLATE = "Live Translate is not in your plan."
        const val NO_AI_NOTES = "AI notes, memory and Ask are not in your plan."

        fun from(plan: PlanDto?, workspace: ConfigWorkspaceDto?): PlanState = PlanState(
            name = plan?.name,
            liveTranslate = plan?.features?.liveTranslate ?: true,
            aiNotes = plan?.features?.aiNotes ?: true,
            workspace = workspace?.name,
            role = workspace?.role,
            monthlyMinutes = plan?.limits?.monthlyMinutes,
            usedMinutes = plan?.used?.minutes
        )

        fun viewerText(workspace: String?): String {
            val where = workspace?.takeIf { it.isNotBlank() } ?: "this workspace"
            return "You are a viewer in $where: you can open what is shared with you, not record."
        }

        fun roleLabel(role: String?): String? = when (role) {
            "owner" -> "Owner"
            "admin" -> "Admin"
            "member" -> "Member"
            "viewer" -> "Viewer"
            else -> null
        }
    }
}
