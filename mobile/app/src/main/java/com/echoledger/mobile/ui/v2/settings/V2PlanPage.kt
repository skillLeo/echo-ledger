package com.echoledger.mobile.ui.v2.settings

import android.content.res.Resources
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.echoledger.mobile.R
import com.echoledger.mobile.core.PlanFormat
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.LimitDto
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.ui.FlowStart
import com.echoledger.mobile.ui.FlowStep
import com.echoledger.mobile.ui.RequestCopy
import com.echoledger.mobile.ui.highlights
import com.echoledger.mobile.ui.manualPayments
import com.echoledger.mobile.ui.nameOf
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.util.Locale
import kotlinx.coroutines.launch

private const val WARN_SHARE = 0.8f
private const val ENDS_SOON_DAYS = 7L
private const val MINUTES_IN_HOUR = 60L
private const val DAYS_IN_YEAR = 365L

/** A limit's words in the screen's language: "120 of 300 min", "1.2 GB of 5.0 GB", "No limit". */
fun planLimitWords(res: Resources, key: String, used: Long?, limit: Long?): String {
    fun number(v: Long) = "%,d".format(Locale.ENGLISH, v)
    fun value(v: Long): String = when (key) {
        "storage_bytes" -> PlanFormat.fileSize(v)
        "monthly_minutes" -> res.getString(R.string.v2_pl_min, number(v))
        "retention_days" -> planDays(res, v)
        "max_file_minutes" -> planHours(res, v)
        else -> number(v)
    }
    return when {
        limit == null -> res.getString(R.string.v2_pl_no_limit)
        used == null -> value(limit)
        key == "monthly_minutes" -> res.getString(R.string.v2_pl_of, number(used), value(limit))
        else -> res.getString(R.string.v2_pl_of, value(used), value(limit))
    }
}

/** 365 → "1 year", 90 → "90 days" (classic's PlanFormat.days, in the screen's language). */
fun planDays(res: Resources, d: Long): String = if (d >= DAYS_IN_YEAR && d % DAYS_IN_YEAR == 0L) {
    (d / DAYS_IN_YEAR).toInt().let { res.getQuantityString(R.plurals.v2_pl_years, it, it) }
} else {
    res.getQuantityString(R.plurals.v2_pl_days, d.toInt(), d.toInt())
}

/** 180 → "3 hours", 90 → "90 minutes". */
fun planHours(res: Resources, minutes: Long): String = if (minutes >= MINUTES_IN_HOUR && minutes % MINUTES_IN_HOUR == 0L) {
    (minutes / MINUTES_IN_HOUR).toInt().let { res.getQuantityString(R.plurals.v2_pl_hours, it, it) }
} else {
    res.getQuantityString(R.plurals.v2_pl_minutes, minutes.toInt(), minutes.toInt())
}

/** A request card's words by stage (classic's RequestCopy.card, in the screen's language). */
data class RequestWords(val title: String, val tag: String, val tone: EchoStatusKind, val body: String)

fun requestWords(res: Resources, r: UpgradeRequestDto, planName: String, is24: Boolean): RequestWords {
    val amount = r.amountDuePkr?.let { PlanFormat.rupees(it) } ?: r.amountDue ?: res.getString(R.string.v2_pl_rq_the_amount)
    fun join(vararg parts: String?) = parts.filterNotNull().filter { it.isNotBlank() }
    return when (r.stage) {
        "awaiting_payment" -> RequestWords(
            res.getString(R.string.v2_pl_rq_requested, planName),
            res.getString(R.string.v2_pl_rq_waiting),
            EchoStatusKind.Neutral,
            join(res.getString(R.string.v2_pl_rq_send, amount), r.referenceCode?.let { res.getString(R.string.v2_pl_rq_reference, it) })
                .joinToString(" ")
        )

        "in_review" -> {
            val at = r.payment?.paidAt
            val whenText = listOfNotNull(PlanFormat.shortDate(at), PlanFormat.time(at, is24)).joinToString(" ")
            RequestWords(
                res.getString(R.string.v2_pl_rq_checking),
                res.getString(R.string.v2_pl_rq_in_review),
                EchoStatusKind.Neutral,
                join(
                    if (whenText.isNotEmpty()) res.getString(R.string.v2_pl_rq_got_on, whenText) else res.getString(R.string.v2_pl_rq_got),
                    r.payment?.reviewHours
                ).joinToString(" ")
            )
        }

        "needs_receipt" -> RequestWords(
            res.getString(R.string.v2_pl_rq_need_receipt),
            res.getString(R.string.v2_pl_rq_action),
            EchoStatusKind.Warning,
            join(r.payment?.proofRequest?.note, res.getString(R.string.v2_pl_rq_still_open)).joinToString("\n\n")
        )

        "declined" -> RequestWords(
            res.getString(R.string.v2_pl_rq_declined),
            res.getString(R.string.v2_pl_rq_not_approved),
            EchoStatusKind.Neutral,
            join(r.reply, res.getString(R.string.v2_pl_rq_declined_body)).joinToString("\n\n")
        )

        "cancelled" -> RequestWords(
            res.getString(R.string.v2_pl_rq_cancelled_title),
            res.getString(R.string.v2_pl_rq_cancelled),
            EchoStatusKind.Neutral,
            res.getString(R.string.v2_pl_rq_cancelled_body)
        )

        else -> {
            val approved = PlanState.planName(r.approvedPlan ?: r.plan).let { if (it == "Pro" && planName != "Pro") planName else it }
            RequestWords(
                res.getString(R.string.v2_pl_rq_on, approved),
                res.getString(R.string.v2_pl_rq_approved),
                EchoStatusKind.Success,
                join(
                    res.getString(R.string.v2_pl_rq_thanks),
                    PlanFormat.longDate(r.approvedUntil)?.let { res.getString(R.string.v2_pl_rq_on_until, planName, it) }
                ).joinToString(" ")
            )
        }
    }
}

/**
 * Plan & usage on the v2 components (classic's page, the same facts and actions): the current plan (Paid until, Ends in
 * N days, Upgrade, Compare plans), an open or recent request with its status and actions (Cancel request asks), this
 * month's minutes with the reset day, storage, devices and team members, the Groq key line on Free, what the plan
 * includes with See every feature (a locked one shows its plan and opens it), and the plans above with Choose.
 * Choosing, paying, a promo code and the receipt are the upgrade flow ([onFlow]).
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun PlanPage(
    plan: PlanState,
    highlight: String?,
    onBack: () -> Unit,
    onOpenKey: () -> Unit,
    onFlow: (FlowStart) -> Unit,
    cancelRequest: suspend (String) -> String?,
    modifier: Modifier = Modifier,
    loadRequest: suspend () -> Unit = {},
    canPay: Boolean = manualPayments
) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    val scope = rememberCoroutineScope()
    var everyFeature by remember { mutableStateOf(false) }
    var confirmCancel by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<String?>(null) }
    var dismissed by rememberSaveable { mutableStateOf(setOf<String>()) }
    val plansAt = remember { BringIntoViewRequester() }
    LaunchedEffect(Unit) { if (canPay) loadRequest() }
    val request = plan.upgrade
    val member = plan.role == "member" || plan.role == "viewer"
    val canBuy = canPay && !member
    val open = request?.open == true
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_plan), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = EchoTokens.Space.s24)) {
            Column(Modifier.highlightedIf(highlight == "plan.upgrade")) {
                CurrentPlan(plan, canBuy && !open, member, showPrice = canBuy, onUpgrade = {
                    onFlow(FlowStart(plan.higherPlans.firstOrNull()?.key))
                }) {
                    scope.launch { plansAt.bringIntoView() }
                }
            }
            if (canBuy && request != null && request.id !in dismissed && RequestCopy.showOnPage(request)) {
                RequestCard(
                    plan,
                    request,
                    onStep = { step -> onFlow(if (step == null) FlowStart(request.plan) else FlowStart(request.plan, step = step)) },
                    onCancel = { confirmCancel = true },
                    onDismiss = { dismissed = dismissed + request.id }
                )
            }
            problem?.let { Note(it) }
            Column(Modifier.highlightedIf(highlight == "plan.usage")) { ThisMonth(res, plan, onOpenKey) }
            Included(res, plan, canPay) { everyFeature = true }
            if (canBuy && plan.higherPlans.isNotEmpty()) {
                Column(Modifier.bringIntoViewRequester(plansAt).highlightedIf(highlight == "plan.pay" || highlight == "plan.promo")) {
                    Plans(plan, enabled = !open) { offer -> onFlow(FlowStart(offer.key)) }
                }
            }
            if (canBuy) Note(stringResource(R.string.v2_pl_bank))
        }
    }
    if (everyFeature) {
        EveryFeature(plan, onClose = { everyFeature = false }) { lock ->
            everyFeature = false
            onFlow(FlowStart(lock.planKey, lock.featureName))
        }
    }
    if (confirmCancel && request != null) {
        EchoDialog(
            title = stringResource(R.string.v2_pl_cancel_title),
            text = stringResource(R.string.v2_pl_cancel_body),
            confirmLabel = stringResource(R.string.v2_pl_cancel_request),
            onConfirm = {
                confirmCancel = false
                scope.launch { problem = cancelRequest(request.id) }
            },
            dismissLabel = stringResource(R.string.v2_pl_keep_request),
            onDismiss = { confirmCancel = false },
            destructive = true
        )
    }
}

@Composable
private fun CurrentPlan(
    plan: PlanState,
    canUpgrade: Boolean,
    member: Boolean,
    /** Prices only where plans are sold: not in the Google Play build, not to a team member or viewer. */
    showPrice: Boolean,
    onUpgrade: () -> Unit,
    onCompare: () -> Unit
) {
    val c = EchoV2.colors
    val paid = !plan.isFree
    val until = PlanFormat.dateWithYear(plan.expiresAt)
    val left = PlanFormat.daysUntil(plan.expiresAt)
    EchoCard(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)) {
        Text(stringResource(R.string.v2_pl_plan, plan.displayName), style = EchoV2.type.titleS, color = c.textPrimary)
        when {
            member -> Line(
                stringResource(R.string.v2_pl_member, plan.workspace ?: stringResource(R.string.v2_pl_your_workspace), plan.displayName)
            )

            !paid -> Line(stringResource(R.string.v2_pl_free_key))

            else -> {
                if (showPrice) {
                    plan.offers.firstOrNull {
                        it.key == plan.key
                    }?.let { o -> PlanFormat.price(o.priceText, o.pricePkr, o.price) }?.let { Line(it) }
                }
                when {
                    until == null -> Line(stringResource(R.string.v2_pl_no_end))

                    left != null && left in 0..ENDS_SOON_DAYS -> Text(
                        if (left == 0L) {
                            stringResource(R.string.v2_pl_ends_today, until)
                        } else {
                            pluralStringResource(R.plurals.v2_pl_ends_in, left.toInt(), left.toInt(), until)
                        },
                        style = EchoV2.type.body,
                        color = c.warning
                    )

                    else -> Line(stringResource(R.string.v2_pl_paid_until, until))
                }
            }
        }
        if (canUpgrade) {
            val next = plan.higherPlans.firstOrNull()
            if (next != null) {
                EchoButton(
                    if (paid) stringResource(R.string.v2_pl_upgrade_to, next.name) else stringResource(R.string.v2_pl_upgrade),
                    onUpgrade,
                    Modifier.fillMaxWidth().padding(top = EchoTokens.Space.s8)
                )
                if (!paid && plan.higherPlans.size > 1) {
                    EchoButton(stringResource(R.string.v2_pl_compare), onCompare, kind = EchoButtonKind.Quiet)
                }
            } else if (paid) {
                Line(stringResource(R.string.v2_pl_top))
            }
        }
    }
    if (paid && until != null) PlanFormat.longDate(plan.expiresAt)?.let { Note(stringResource(R.string.v2_pl_stops_on, it)) }
}

@Composable
private fun RequestCard(plan: PlanState, r: UpgradeRequestDto, onStep: (FlowStep?) -> Unit, onCancel: () -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val words = requestWords(context.resources, r, plan.nameOf(r.plan), android.text.format.DateFormat.is24HourFormat(context))
    val openLabel = stringResource(R.string.v2_pl_open_request)
    EchoCard(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
            .clickable(onClickLabel = openLabel) { onStep(FlowStep.Status) }
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            Text(words.title, style = EchoV2.type.bodyStrong, color = c.textPrimary, modifier = Modifier.weight(1f))
            EchoStatus(words.tag, words.tone)
        }
        Text(words.body, style = EchoV2.type.body, color = c.textSecondary)
        val full = Modifier.fillMaxWidth()
        when (r.stage) {
            "awaiting_payment" -> {
                EchoButton(stringResource(R.string.v2_pl_payment_details), { onStep(FlowStep.Pay) }, full)
                EchoButton(stringResource(R.string.v2_pl_cancel_request), onCancel, kind = EchoButtonKind.Quiet)
            }

            "in_review" -> EchoButton(stringResource(R.string.v2_pl_view_request), {
                onStep(FlowStep.Status)
            }, full, kind = EchoButtonKind.Secondary)

            "needs_receipt" -> {
                EchoButton(stringResource(R.string.v2_pl_add_receipt), { onStep(FlowStep.Receipt) }, full)
                EchoButton(stringResource(R.string.v2_pl_payment_details), { onStep(FlowStep.Pay) }, kind = EchoButtonKind.Quiet)
            }

            "declined" -> Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoButton(stringResource(R.string.v2_pl_request_again), { onStep(FlowStep.Choose) }, kind = EchoButtonKind.Secondary)
                EchoButton(stringResource(R.string.v2_pl_dismiss), onDismiss, kind = EchoButtonKind.Quiet)
            }

            else -> EchoButton(stringResource(R.string.v2_pl_dismiss), onDismiss, kind = EchoButtonKind.Quiet)
        }
    }
}

/** This month: transcription minutes (or the Groq key line on Free), storage, devices and team members as meters. */
@Composable
private fun ThisMonth(res: Resources, plan: PlanState, onOpenKey: () -> Unit) {
    val minutes = plan.limits["monthly_minutes"]
    val reset = PlanFormat.shortDate(minutes?.resetsAt) ?: PlanFormat.nextReset()
    val hasMinutes = (minutes?.limit ?: 0) > 0
    val storage = plan.limits["storage_bytes"]
    val devices = plan.limits["devices"]
    val seats = plan.limits["seats"]?.takeIf { (it.limit ?: 0) > 1 }
    val keyLine = !hasMinutes && plan.needsOwnKey
    if (!keyLine && minutes == null && storage == null && devices == null && seats == null) return
    EchoSectionHeader(stringResource(R.string.v2_pl_this_month))
    if (keyLine) {
        EchoSettingRow(
            stringResource(R.string.v2_pl_transcription),
            subtitle = stringResource(if (plan.transcriptionKey?.set == true) R.string.v2_pl_key_added else R.string.v2_pl_key_not_added),
            value = stringResource(R.string.v2_pl_your_key),
            onClick = onOpenKey
        )
    } else if (minutes != null) {
        val f = PlanFormat.fraction(minutes.used, minutes.limit)
        Meter(
            stringResource(R.string.v2_pl_transcription),
            planLimitWords(res, "monthly_minutes", minutes.used, minutes.limit),
            f,
            when {
                f != null && f >= 1f -> stringResource(R.string.v2_pl_paused_until, reset)
                f != null && f >= WARN_SHARE -> stringResource(R.string.v2_pl_used_pct, Math.round(f * 100), reset)
                else -> stringResource(R.string.v2_pl_resets, reset)
            }
        )
    }
    storage?.let { MeterOf(res, it, "storage_bytes", R.string.v2_pl_storage, R.string.v2_pl_storage_full) }
    devices?.let { MeterOf(res, it, "devices", R.string.v2_pl_devices, R.string.v2_pl_devices_full) }
    seats?.let { MeterOf(res, it, "seats", R.string.v2_pl_team, null) }
    if (hasMinutes) Note(stringResource(R.string.v2_pl_minutes_reset))
}

@Composable
private fun MeterOf(res: Resources, l: LimitDto, key: String, title: Int, full: Int?) {
    val f = PlanFormat.fraction(l.used, l.limit)
    Meter(
        stringResource(title),
        planLimitWords(res, key, l.used, l.limit),
        f,
        full?.takeIf {
            f != null && f >= 1f
        }?.let { stringResource(it) }
    )
}

/** A limit's row: its name and "x of y", a bar read as a progress bar, and a line under it when it matters. */
@Composable
private fun Meter(title: String, words: String, fraction: Float?, note: String?) {
    val c = EchoV2.colors
    Column(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
            .semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(title, style = EchoV2.type.body, color = c.textPrimary, modifier = Modifier.weight(1f))
            Text(words, style = EchoV2.type.meta, color = c.textSecondary)
        }
        fraction?.let { share ->
            androidx.compose.foundation.layout.Box(
                Modifier.fillMaxWidth().height(EchoTokens.Space.s4)
                    .background(c.bgSubtle, RoundedCornerShape(EchoTokens.Radius.full))
                    // Read as a progress bar named for its limit, its state the words ("Storage, 1.1 GB of 4.7 GB").
                    .semantics {
                        progressBarRangeInfo = ProgressBarRangeInfo(share, 0f..1f)
                        contentDescription = title
                        stateDescription = words
                    }
            ) {
                androidx.compose.foundation.layout.Box(
                    Modifier.fillMaxWidth(share).height(EchoTokens.Space.s4)
                        .background(if (share >= 1f) c.warning else c.accent, RoundedCornerShape(EchoTokens.Radius.full))
                )
            }
        }
        note?.let { Text(it, style = EchoV2.type.meta, color = if (fraction != null && fraction >= 1f) c.warning else c.textSecondary) }
    }
}

/** Included in your plan: how long recordings are kept, the longest recording, uploads a day, team members; every feature. */
@Composable
private fun Included(res: Resources, plan: PlanState, canPay: Boolean, onEveryFeature: () -> Unit) {
    val facts = buildList {
        plan.limits["retention_days"]?.limit?.let { add(R.string.v2_pl_kept to planDays(res, it)) }
        plan.limits["max_file_minutes"]?.limit?.let { add(R.string.v2_pl_longest to planHours(res, it)) }
        plan.limits["uploads_per_day"]?.limit?.let {
            add(R.string.v2_pl_uploads to res.getString(R.string.v2_pl_up_to, "%,d".format(Locale.ENGLISH, it)))
        }
        plan.limits["seats"]?.limit?.let {
            add(
                R.string.v2_pl_team to
                    if (it <= 1) res.getString(R.string.v2_pl_just_you) else res.getString(R.string.v2_pl_up_to_people, it.toInt())
            )
        }
    }
    if (facts.isEmpty() && plan.allFeatures.isEmpty()) return
    EchoSectionHeader(stringResource(R.string.v2_pl_included))
    facts.forEach { (title, value) -> EchoSettingRow(stringResource(title), value = value) }
    if (plan.allFeatures.isNotEmpty() && canPay) {
        EchoSettingRow(stringResource(R.string.v2_pl_every_feature), icon = EchoIcons.Plan, onClick = onEveryFeature)
    }
    if (plan.isFree) Note(stringResource(R.string.v2_pl_every_plan))
}

/** The plans above this one, cheapest first: name, price, what each adds, and Choose (not while a request is open). */
@Composable
private fun Plans(plan: PlanState, enabled: Boolean, onChoose: (PlanOfferDto) -> Unit) {
    val c = EchoV2.colors
    EchoSectionHeader(stringResource(R.string.v2_pl_plans))
    plan.higherPlans.forEachIndexed { i, offer ->
        EchoCard(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(offer.name, style = EchoV2.type.bodyStrong, color = c.textPrimary, modifier = Modifier.weight(1f))
                PlanFormat.price(offer.priceText, offer.pricePkr, offer.price)?.let {
                    Text(it, style = EchoV2.type.body, color = c.textSecondary)
                }
            }
            if (i >
                0
            ) {
                Text(
                    stringResource(R.string.v2_pl_everything_in, plan.higherPlans[i - 1].name),
                    style = EchoV2.type.body,
                    color = c.textSecondary
                )
            }
            plan.highlights(offer).forEach { Text(it, style = EchoV2.type.content(it, EchoV2.type.body), color = c.textPrimary) }
            if (enabled) {
                EchoButton(
                    stringResource(R.string.v2_pl_choose, offer.name),
                    { onChoose(offer) },
                    Modifier.fillMaxWidth().padding(top = EchoTokens.Space.s8),
                    kind = EchoButtonKind.Secondary
                )
            }
        }
    }
}

/** Every feature, full screen: Included, or the cheapest plan that has it (its badge; a tap opens that plan). */
@Composable
private fun EveryFeature(plan: PlanState, onClose: () -> Unit, onLocked: (PlanState.Lock) -> Unit) {
    val c = EchoV2.colors
    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Column(Modifier.fillMaxSize().background(c.bgCanvas)) {
            EchoTopBar(stringResource(R.string.v2_pl_every_feature_title), onBack = onClose)
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
                val included = stringResource(R.string.v2_pl_feature_included)
                plan.allFeatures.forEach { (key, feature) ->
                    val lock = plan.lockFor(key)
                    val name = feature.name.ifBlank { key }
                    EchoSettingRow(
                        name,
                        subtitle = feature.description.takeIf { it.isNotBlank() },
                        onClick = lock?.let { { onLocked(it) } },
                        trailing = {
                            if (lock == null) {
                                Icon(EchoIcons.Check, included, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
                            } else {
                                EchoStatus(lock.badge, EchoStatusKind.Accent)
                            }
                        }
                    )
                }
            }
        }
    }
}

@Composable
private fun Line(words: String) = Text(words, style = EchoV2.type.body, color = EchoV2.colors.textSecondary)

@Composable
private fun Note(words: String) {
    Text(
        words,
        style = EchoV2.type.meta,
        color = EchoV2.colors.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
}
