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
    val usedMinutes: Long? = null,
    /** Since v1.6 (`GET /api/me/entitlements`): the plan's badge and colour, every feature, the limits and the offers. */
    val key: String? = null,
    val badge: String? = null,
    val color: String? = null,
    val expiresAt: String? = null,
    val features: Map<String, com.echoledger.mobile.data.FeatureDto> = emptyMap(),
    val limits: Map<String, com.echoledger.mobile.data.LimitDto> = emptyMap(),
    val offers: List<com.echoledger.mobile.data.PlanOfferDto> = emptyList(),
    val transcriptionKey: com.echoledger.mobile.data.TranscriptionKeyDto? = null,
    val upgrade: com.echoledger.mobile.data.UpgradeRequestDto? = null
) {
    /** Every feature in the server's order (since v1.6.2, "All features"): included ones and locked ones alike. */
    val allFeatures: List<Pair<String, com.echoledger.mobile.data.FeatureDto>> get() = features.entries.map { it.key to it.value }

    /** How many of [allFeatures] this plan has. */
    val includedCount: Int get() = features.values.count { it.enabled }

    /** Whether the plan includes [feature]; a server older than v1.6 says nothing, so everything is on. */
    fun allows(feature: String): Boolean = features[feature]?.enabled ?: true

    /** The badge of the cheapest plan with [feature] (its name and [PLAN_MARK]), or null when this plan has it. */
    fun lockFor(feature: String): Lock? {
        val f = features[feature] ?: return null
        if (f.enabled) return null
        val offer = offers.firstOrNull { it.key == f.lowestPlan }
        return Lock(
            feature = feature,
            featureName = f.name.ifBlank { feature },
            description = f.description,
            planKey = f.lowestPlan ?: offer?.key.orEmpty(),
            badge = badgeText(offer?.name?.ifBlank { null } ?: planName(f.lowestPlan)),
            color = null
        )
    }

    /** A Free plan transcribes with the person's own key (or on a computer): true when the platform's engines are not in it. */
    val needsOwnKey: Boolean get() = features["platform_transcription"]?.enabled == false

    data class Lock(
        val feature: String,
        val featureName: String,
        val description: String,
        val planKey: String,
        val badge: String,
        val color: String?
    )

    /** A viewer opens what is shared with them and records nothing. */
    val viewer: Boolean get() = role == "viewer"

    /**
     * The line next to the plan on the Settings card (since v1.7): "120 of 300 min this month" when the plan has
     * minutes, nothing on Free (its limit is 0: it transcribes with the person's own key), null before the server said.
     */
    fun summary(): String? {
        name?.takeIf { it.isNotBlank() } ?: return null
        val limit = monthlyMinutes ?: return null
        if (limit <= 0) return null
        return "${usedMinutes ?: 0} of ${"%,d".format(limit)} min this month"
    }

    /** The plan's display name ("Free", "Pro", "Ultra"), never its key. */
    val displayName: String get() = name?.takeIf { it.isNotBlank() } ?: planName(key)

    /** The plans above this one, cheapest first (the Plans section and the upgrade flow offer only these). */
    val higherPlans: List<com.echoledger.mobile.data.PlanOfferDto>
        get() {
            val mine = offers.indexOfFirst { it.key == key }
            return offers.filterIndexed { i, o -> i > mine && o.key != "free" && (o.pricePkr ?: 1) > 0 }
        }

    /** Free with the platform's engines off: the person transcribes with their own Groq key. */
    val isFree: Boolean get() = key == "free" || (key == null && name.equals("Free", ignoreCase = true))

    /** The top plan: nothing to upgrade to. */
    val isTop: Boolean get() = offers.isNotEmpty() && higherPlans.isEmpty()

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

        /**
         * A plan tag's text (since v1.7): the plan's name in sentence case, "Pro" or "Ultra", with no mark and no
         * capitals, the same neutral tag on every app (docs/DESIGN_SYSTEM.md "Settings").
         */
        fun badgeText(name: String): String = planName(name)

        /** "business" or "BUSINESS" → "Ultra" (its display name), "PRO" → "Pro"; null or blank → "Pro". */
        fun planName(keyOrName: String?): String {
            val t = keyOrName?.trim().orEmpty()
            return when (t.lowercase()) {
                "" -> "Pro"
                "business", "ultra" -> "Ultra"
                else -> t.lowercase().replaceFirstChar { it.titlecase() }
            }
        }

        /** The v1.6 entitlements on top of what the config said (an older server's answer keeps [base]). */
        fun withEntitlements(base: PlanState, e: com.echoledger.mobile.data.EntitlementsDto): PlanState {
            fun on(feature: String, default: Boolean) = e.features[feature]?.enabled ?: default
            return base.copy(
                name = e.plan.name.ifBlank { base.name ?: "" }.ifBlank { null },
                key = e.plan.key.ifBlank { null },
                badge = e.plan.badge.ifBlank { null },
                color = e.plan.color,
                expiresAt = e.plan.expiresAt,
                liveTranslate = on("live_translate", base.liveTranslate),
                aiNotes = on("ai_notes", base.aiNotes),
                monthlyMinutes = e.limits["monthly_minutes"]?.limit ?: base.monthlyMinutes,
                usedMinutes = e.limits["monthly_minutes"]?.used ?: base.usedMinutes,
                features = e.features,
                limits = e.limits,
                offers = e.plans,
                transcriptionKey = e.transcriptionKey,
                upgrade = e.upgradeRequest
            )
        }

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
