package com.echoledger.mobile.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.core.PlanFormat
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.MeterRow
import com.echoledger.mobile.ui.design.PlanTag
import com.echoledger.mobile.ui.design.RowDivider
import com.echoledger.mobile.ui.design.SettingsSection
import kotlinx.coroutines.launch

/** Whether this build sells plans by bank transfer (since v1.7: the Google Play build shows plans, never prices). */
internal val manualPayments: Boolean get() = BuildConfig.MANUAL_PAYMENTS

/**
 * A feature the plan lacks (since v1.7): the neutral tag of the cheapest plan that has it ("Pro", "Ultra"); a tap opens
 * the upgrade flow on that plan. No plan colours, locks or stars.
 */
@Composable
fun PlanBadge(lock: PlanState.Lock, onClick: () -> Unit, modifier: Modifier = Modifier) {
    PlanTag(
        lock.badge,
        modifier.clip(RoundedCornerShape(Radius.tag)).clickable(role = Role.Button, onClickLabel = "See the plans", onClick = onClick)
            .semantics { contentDescription = "${lock.featureName} is on the ${lock.badge} plan" }
    )
}

/** The plan's name for a request or offer key ("business" → "Ultra"). */
internal fun PlanState.nameOf(key: String?): String =
    offers.firstOrNull { it.key == key }?.name?.takeIf { it.isNotBlank() } ?: PlanState.planName(key)

/** The highlight lines of a plan card: its minutes, team size and the paid features the plan below it lacks (at most 5). */
internal fun PlanState.highlights(offer: PlanOfferDto): List<String> {
    val index = offers.indexOf(offer)
    val below = offers.getOrNull(index - 1)?.features.orEmpty().toSet()
    val out = mutableListOf<String>()
    offer.limits["monthly_minutes"]?.takeIf { it > 0 }?.let { out += "%,d transcription minutes a month".format(it) }
    offer.limits["seats"]?.takeIf { it > 1 }?.let { out += "A team of up to $it people" }
    offer.features.filter { it !in below }.forEach { key -> features[key]?.name?.takeIf { it.isNotBlank() }?.let { out += it } }
    return out.distinct().take(5)
}

/** You → Plan & usage, the card's renderer in Settings (the name the settings pages call). */
@Composable
fun PlanSection(vm: MainViewModel, plan: PlanState) = PlanUsagePage(vm, plan)

/**
 * Plan & usage (since v1.7, docs/DESIGN_SYSTEM.md "Settings"): the current plan, an open or recent request, this month's
 * use as plain meters, what the plan includes, and only the plans above it. One filled button at most; while a request is
 * open its card carries the action and nothing says "Upgrade".
 */
@Composable
fun PlanUsagePage(vm: MainViewModel, plan: PlanState, onOpenKey: () -> Unit = {}) {
    val scope = rememberCoroutineScope()
    var flow by remember { mutableStateOf<FlowStart?>(null) }
    var everyFeature by remember { mutableStateOf(false) }
    var confirmCancel by remember { mutableStateOf(false) }
    var dismissed by rememberSaveable { mutableStateOf(setOf<String>()) }
    val plansAt = remember { BringIntoViewRequester() }
    LaunchedEffect(Unit) { if (manualPayments) vm.loadUpgradeRequest() }
    val request = plan.upgrade
    val member = plan.role == "member" || plan.role == "viewer"
    val canBuy = manualPayments && !member
    val open = request?.open == true
    Column(Modifier.fillMaxWidth()) {
        CurrentPlanCard(plan, canBuy && !open, member, onUpgrade = { flow = FlowStart(plan.higherPlans.firstOrNull()?.key) }, onCompare = {
            scope.launch { plansAt.bringIntoView() }
        })
        if (canBuy && request != null && request.id !in dismissed && RequestCopy.showOnPage(request)) {
            RequestCard(
                plan,
                request,
                onPrimary = { step -> flow = if (step == null) FlowStart(request.plan) else FlowStart(request.plan, step = step) },
                onCancel = { confirmCancel = true },
                onDismiss = { dismissed = dismissed + request.id }
            )
        }
        ThisMonth(plan, onOpenKey)
        Included(plan, onEveryFeature = { everyFeature = true })
        if (canBuy && plan.higherPlans.isNotEmpty()) {
            Column(Modifier.bringIntoViewRequester(plansAt)) {
                PlansSection(plan, enabled = !open) { offer -> flow = FlowStart(offer.key) }
            }
        }
        if (canBuy) {
            Text(
                "Payments are by bank transfer. We switch your plan on after we see the money in our account.",
                style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.Normal),
                color = Brand.Muted,
                modifier = Modifier.padding(horizontal = Space.screen + Space.s1).padding(bottom = Space.s5)
            )
        }
    }
    flow?.let { start -> UpgradeFlow(vm, start.planKey, start.feature, start.step, given = plan) { flow = null } }
    if (everyFeature) EveryFeatureDialog(plan) { everyFeature = false }
    if (confirmCancel && request != null) {
        CancelRequestDialog(onKeep = { confirmCancel = false }) {
            confirmCancel = false
            scope.launch { vm.cancelUpgradeRequest(request.id).error?.let { vm.showMessage(it) } }
        }
    }
}

/** Where the upgrade flow opens: on [planKey] (or the next plan up), at [step] (or where the open request stands). */
data class FlowStart(val planKey: String?, val feature: String? = null, val step: FlowStep? = null)

@Composable
private fun CurrentPlanCard(plan: PlanState, canUpgrade: Boolean, member: Boolean, onUpgrade: () -> Unit, onCompare: () -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val paid = !plan.isFree
    val until = PlanFormat.dateWithYear(plan.expiresAt)
    val left = PlanFormat.daysUntil(plan.expiresAt)
    SettingsSection(
        null,
        footer = if (paid &&
            until != null
        ) {
            "Your plan stops on ${PlanFormat.longDate(plan.expiresAt)}. Nothing is charged automatically."
        } else {
            null
        }
    ) {
        Column(Modifier.fillMaxWidth().padding(Space.s4), verticalArrangement = Arrangement.spacedBy(Space.s1)) {
            Text("${plan.displayName} plan", style = type.titleM, color = c.text)
            if (member) {
                Text(
                    "${plan.workspace ?: "Your workspace"} is on ${plan.displayName}. Its owner manages the plan.",
                    style = type.body,
                    color = c.textMuted
                )
            } else if (!paid) {
                Text("Transcripts use your own free Groq key.", style = type.body, color = c.textMuted)
            } else {
                if (manualPayments) {
                    plan.offers.firstOrNull { it.key == plan.key }?.let { o -> PlanFormat.price(o.priceText, o.pricePkr, o.price) }?.let {
                        Text(it, style = type.body, color = c.textMuted)
                    }
                }
                when {
                    until == null -> Text("No end date", style = type.body, color = c.textMuted)

                    left != null && left in 0..7 -> Text(
                        if (left == 0L) "Ends today, $until." else "Ends in $left day${if (left == 1L) "" else "s"}, on $until.",
                        style = type.body,
                        color = c.warning
                    )

                    else -> Text("Paid until $until", style = type.body, color = c.textMuted)
                }
            }
            if (canUpgrade) {
                Spacer(Modifier.size(Space.s2))
                val next = plan.higherPlans.firstOrNull()
                if (next != null) {
                    EchoButton(if (paid) "Upgrade to ${next.name}" else "Upgrade", onUpgrade, Modifier.fillMaxWidth())
                    if (!paid && plan.higherPlans.size > 1) EchoButton("Compare plans", onCompare, kind = ButtonKind.Text)
                } else if (paid) {
                    Text("You're on our top plan.", style = type.body, color = c.textMuted)
                }
            }
        }
    }
}

/** The words of a request card and the status page, by stage (docs/DESIGN_SYSTEM.md "Settings"). Pure. */
object RequestCopy {
    data class Card(val title: String, val tag: String, val tagTone: String, val body: String)

    /** Open requests always; a decline for 14 days and an approval for 7; never a cancelled one. */
    fun showOnPage(r: UpgradeRequestDto, today: java.time.LocalDate = java.time.LocalDate.now()): Boolean {
        val decided = PlanFormat.parse(r.decidedAt)?.atZone(java.time.ZoneId.systemDefault())?.toLocalDate()
        val age = decided?.let { java.time.temporal.ChronoUnit.DAYS.between(it, today) } ?: 0
        return when (r.stage) {
            "awaiting_payment", "in_review", "needs_receipt" -> true
            "declined" -> age <= 14
            "approved" -> age <= 7
            else -> false
        }
    }

    fun card(r: UpgradeRequestDto, planName: String, is24: Boolean): Card {
        val amount = r.amountDuePkr?.let { PlanFormat.rupees(it) } ?: r.amountDue ?: "the amount"
        return when (r.stage) {
            "awaiting_payment" -> Card(
                "$planName requested",
                "Waiting for payment",
                "muted",
                "Send $amount to our bank account, then add a screenshot of the receipt." +
                    (r.referenceCode?.let { " Reference $it." } ?: "")
            )

            "in_review" -> {
                val at = r.payment?.paidAt
                val whenText = listOfNotNull(PlanFormat.shortDate(at), PlanFormat.time(at, is24)).joinToString(" at ")
                Card(
                    "Checking your payment",
                    "In review",
                    "muted",
                    listOfNotNull(
                        if (whenText.isNotEmpty()) "We got your receipt on $whenText." else "We got your receipt.",
                        r.payment?.reviewHours
                    ).joinToString(" ")
                )
            }

            "needs_receipt" -> Card(
                "We need another receipt",
                "Action needed",
                "warning",
                listOfNotNull(
                    r.payment?.proofRequest?.note?.takeIf {
                        it.isNotBlank()
                    },
                    "Your request is still open. You don't need to pay again."
                ).joinToString("\n\n")
            )

            "declined" -> Card(
                "We couldn't confirm your payment",
                "Not approved",
                "muted",
                listOfNotNull(
                    r.reply?.takeIf {
                        it.isNotBlank()
                    },
                    "If you've paid, reply to our email with your transaction ID and we'll look again."
                ).joinToString("\n\n")
            )

            "cancelled" -> Card(
                "Request cancelled",
                "Cancelled",
                "muted",
                "You cancelled this request. Cancelling doesn't refund a payment."
            )

            else -> Card(
                "You're on ${PlanState.planName(r.approvedPlan ?: r.plan).let { if (it == "Pro" && planName != "Pro") planName else it }}",
                "Approved",
                "success",
                "Thank you." + (PlanFormat.longDate(r.approvedUntil)?.let { " $planName is on until $it." } ?: "")
            )
        }
    }
}

@Composable
private fun RequestCard(
    plan: PlanState,
    r: UpgradeRequestDto,
    onPrimary: (FlowStep?) -> Unit,
    onCancel: () -> Unit,
    onDismiss: () -> Unit
) {
    val context = LocalContext.current
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val copy = RequestCopy.card(r, plan.nameOf(r.plan), android.text.format.DateFormat.is24HourFormat(context))
    SettingsSection(null) {
        Column(
            Modifier.fillMaxWidth().clickable(onClickLabel = "Open the request") { onPrimary(FlowStep.Status) }.padding(Space.s4),
            verticalArrangement = Arrangement.spacedBy(Space.s2)
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(copy.title, style = type.bodyL.copy(fontWeight = FontWeight.SemiBold), color = c.text, modifier = Modifier.weight(1f))
                PlanTag(
                    copy.tag,
                    color = when (copy.tagTone) {
                        "warning" -> c.warning
                        "success" -> c.success
                        else -> c.textMuted
                    }
                )
            }
            Text(copy.body, style = type.body, color = c.textMuted)
            when (r.stage) {
                "awaiting_payment" -> {
                    EchoButton("Payment details", { onPrimary(FlowStep.Pay) }, Modifier.fillMaxWidth())
                    EchoButton("Cancel request", onCancel, kind = ButtonKind.Text)
                }

                "in_review" -> EchoButton("View request", {
                    onPrimary(FlowStep.Status)
                }, Modifier.fillMaxWidth(), kind = ButtonKind.Secondary)

                "needs_receipt" -> {
                    EchoButton("Add receipt", { onPrimary(FlowStep.Receipt) }, Modifier.fillMaxWidth())
                    EchoButton("Payment details", { onPrimary(FlowStep.Pay) }, kind = ButtonKind.Text)
                }

                "declined" -> Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                    EchoButton("Request again", { onPrimary(FlowStep.Choose) }, kind = ButtonKind.Secondary)
                    EchoButton("Dismiss", onDismiss, kind = ButtonKind.Text)
                }

                else -> EchoButton("Dismiss", onDismiss, kind = ButtonKind.Text)
            }
        }
    }
}

@Composable
private fun ThisMonth(plan: PlanState, onOpenKey: () -> Unit) {
    val minutes = plan.limits["monthly_minutes"]
    val reset = PlanFormat.shortDate(minutes?.resetsAt) ?: PlanFormat.nextReset()
    val hasMinutes = (minutes?.limit ?: 0) > 0
    val rows = mutableListOf<@Composable () -> Unit>()
    if (!hasMinutes && plan.needsOwnKey) {
        rows += {
            val set = plan.transcriptionKey?.set == true
            com.echoledger.mobile.ui.design.SettingsRow(
                icon = null,
                title = "Transcription",
                summary = if (set) "Key added" else "Not added. Tap to add your key",
                value = "Your Groq key",
                onClick = onOpenKey
            )
        }
    } else if (minutes != null) {
        rows += {
            val f = PlanFormat.fraction(minutes.used, minutes.limit)
            val pct = f?.let { Math.round(it * 100) } ?: 0
            MeterRow(
                "Transcription",
                PlanFormat.limit("monthly_minutes", minutes.used, minutes.limit),
                f,
                when {
                    f != null && f >= 1f -> "Transcription is paused until $reset."
                    f != null && f >= 0.8f -> "You've used $pct% of your minutes. They reset on $reset."
                    else -> "Resets $reset"
                }
            )
        }
    }
    plan.limits["storage_bytes"]?.let { l ->
        rows += {
            val f = PlanFormat.fraction(l.used, l.limit)
            MeterRow(
                "Storage",
                PlanFormat.limit("storage_bytes", l.used, l.limit),
                f,
                if (f != null &&
                    f >= 1f
                ) {
                    "New recordings can't upload until you free up space."
                } else {
                    null
                }
            )
        }
    }
    plan.limits["devices"]?.let { l ->
        rows += {
            val f = PlanFormat.fraction(l.used, l.limit)
            MeterRow(
                "Devices",
                PlanFormat.limit("devices", l.used, l.limit),
                f,
                if (f != null &&
                    f >= 1f
                ) {
                    "Sign out a device to add another."
                } else {
                    null
                }
            )
        }
    }
    plan.limits["seats"]?.takeIf { (it.limit ?: 0) > 1 }?.let { l ->
        rows +=
            {
                MeterRow(
                    "Team members",
                    PlanFormat.limit("seats", l.used, l.limit),
                    PlanFormat.fraction(l.used, l.limit),
                    null,
                    blocking = false
                )
            }
    }
    if (rows.isEmpty()) return
    SettingsSection(
        "This month",
        footer = if (hasMinutes) "Minutes reset on the 1st of each month. Unused minutes don't carry over." else null
    ) {
        rows.forEachIndexed { i, row ->
            if (i > 0) RowDivider(inset = false)
            row()
        }
    }
}

@Composable
private fun Included(plan: PlanState, onEveryFeature: () -> Unit) {
    val facts = buildList {
        plan.limits["retention_days"]?.limit?.let { add("Recordings kept" to PlanFormat.days(it)) }
        plan.limits["max_file_minutes"]?.limit?.let { add("Longest recording" to PlanFormat.hours(it)) }
        plan.limits["uploads_per_day"]?.limit?.let { add("Uploads a day" to "Up to %,d".format(it)) }
        plan.limits["seats"]?.limit?.let { add("Team members" to if (it <= 1) "Just you" else "Up to $it people") }
    }
    if (facts.isEmpty() && plan.allFeatures.isEmpty()) return
    SettingsSection(
        "Included in your plan",
        footer = if (plan.isFree) "Every plan can record, organise, search, share links and export." else null
    ) {
        facts.forEachIndexed { i, (title, value) ->
            if (i > 0) RowDivider(inset = false)
            com.echoledger.mobile.ui.design.SettingsRow(null, title, value = value)
        }
        if (plan.allFeatures.isNotEmpty() && manualPayments) {
            if (facts.isNotEmpty()) RowDivider(inset = false)
            com.echoledger.mobile.ui.design.SettingsRow(null, "See every feature", onClick = onEveryFeature, trailing = { Chevron() })
        }
    }
}

@Composable
internal fun Chevron() {
    Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = Brand.Faint, modifier = Modifier.size(20.dp))
}

@Composable
private fun PlansSection(plan: PlanState, enabled: Boolean, onChoose: (PlanOfferDto) -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    Text(
        "Plans",
        style = type.labelM.copy(fontWeight = FontWeight.SemiBold),
        color = c.textMuted,
        modifier = Modifier.padding(start = Space.screen + Space.s1, bottom = Space.s2)
    )
    plan.higherPlans.forEachIndexed { i, offer ->
        Column(
            Modifier.fillMaxWidth().padding(horizontal = Space.screen).padding(bottom = Space.s3).clip(RoundedCornerShape(Radius.md))
                .background(c.surface).border(1.dp, c.border, RoundedCornerShape(Radius.md)).padding(Space.s4),
            verticalArrangement = Arrangement.spacedBy(Space.s2)
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(offer.name, style = type.bodyL.copy(fontWeight = FontWeight.SemiBold), color = c.text, modifier = Modifier.weight(1f))
                PlanFormat.price(offer.priceText, offer.pricePkr, offer.price)?.let { Text(it, style = type.body, color = c.textMuted) }
            }
            if (i > 0) Text("Everything in ${plan.higherPlans[i - 1].name}, plus:", style = type.body, color = c.textMuted)
            plan.highlights(offer).forEach { Text(it, style = type.body, color = c.text) }
            if (enabled) EchoButton("Choose ${offer.name}", { onChoose(offer) }, Modifier.fillMaxWidth(), kind = ButtonKind.Secondary)
        }
    }
}

/** "See every feature": every feature, "Included" or the tag of the cheapest plan that has it. Full screen. */
@Composable
private fun EveryFeatureDialog(plan: PlanState, onDismiss: () -> Unit) {
    val type = LocalEchoType.current
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize(), color = Brand.Background) {
            Column(Modifier.fillMaxSize()) {
                com.echoledger.mobile.ui.design.EchoTopBar(
                    "Every feature",
                    large = false,
                    navigation = { com.echoledger.mobile.ui.design.BarIcon(Icons.Rounded.Close, "Close", onDismiss, tint = Brand.Text) }
                )
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = Space.s5)) {
                    SettingsSection(null) {
                        plan.allFeatures.forEachIndexed { i, (key, feature) ->
                            if (i > 0) RowDivider(inset = false)
                            val lock = plan.lockFor(key)
                            Row(
                                Modifier.fillMaxWidth().heightIn(min = 56.dp).padding(horizontal = Space.screen, vertical = Space.s3),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Column(Modifier.weight(1f)) {
                                    Text(feature.name.ifBlank { key }, style = type.body, color = Brand.Text)
                                    if (feature.description.isNotBlank()) {
                                        Text(
                                            feature.description,
                                            style = type.labelM.copy(fontWeight = FontWeight.Normal),
                                            color = Brand.Muted
                                        )
                                    }
                                }
                                Spacer(Modifier.width(Space.s3))
                                if (lock == null) {
                                    Icon(
                                        Icons.Rounded.Check,
                                        contentDescription = "Included",
                                        tint = Brand.Muted,
                                        modifier = Modifier.size(20.dp)
                                    )
                                } else {
                                    PlanTag(lock.badge)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * A paid feature the plan lacks, shown where it would be (since v1.6.2): its name, what it does and the plan's tag; a
 * tap opens the upgrade flow on that plan (or, in the Google Play build, says which plan has it).
 */
@Composable
fun LockedFeature(vm: MainViewModel, plan: PlanState, feature: String, title: String, modifier: Modifier = Modifier) {
    val lock = plan.lockFor(feature) ?: return
    var sheet by remember { mutableStateOf(false) }
    Column(
        modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(Brand.Surface).clickable { sheet = true }.padding(Space.s3),
        verticalArrangement = Arrangement.spacedBy(4.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(title, fontWeight = FontWeight.SemiBold, color = Brand.Text, modifier = Modifier.weight(1f))
            PlanBadge(lock, onClick = { sheet = true })
        }
        if (lock.description.isNotBlank()) Text(lock.description, color = Brand.Muted, fontSize = 13.sp)
    }
    if (sheet) UpgradeSheet(vm, plan, lock) { sheet = false }
}

/** Every place that offered the old upgrade sheet now opens the upgrade flow on the locked feature's plan. */
@Composable
fun UpgradeSheet(vm: MainViewModel, plan: PlanState, lock: PlanState.Lock?, onDismiss: () -> Unit) {
    if (!manualPayments || plan.role == "member" || plan.role == "viewer") {
        // Google Play build and team members: say which plan has it, nothing to buy here.
        androidx.compose.material3.AlertDialog(
            onDismissRequest = onDismiss,
            text = { Text(lock?.let { "${it.featureName} is on the ${it.badge} plan." } ?: "Your plan is managed outside this app.") },
            confirmButton = { androidx.compose.material3.TextButton(onClick = onDismiss) { Text("OK") } }
        )
        return
    }
    UpgradeFlow(vm, lock?.planKey, lock?.featureName, null, given = plan, onDismiss = onDismiss)
}

/** "Cancel this request?" with the reminder that cancelling refunds nothing. */
@Composable
internal fun CancelRequestDialog(onKeep: () -> Unit, onCancel: () -> Unit) {
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onKeep,
        title = { Text("Cancel this request?") },
        text = { Text("If you've already paid, add your receipt instead. Cancelling doesn't refund a payment.") },
        confirmButton = {
            androidx.compose.material3.TextButton(onClick = onCancel) { Text("Cancel request", color = LocalEchoColors.current.danger) }
        },
        dismissButton = { androidx.compose.material3.TextButton(onClick = onKeep) { Text("Keep request") } }
    )
}

/**
 * You → Transcription key (since v1.6, the Free plan): this person's own free Groq key, in three steps; the server checks
 * it and keeps it encrypted for them only. A plan with EchoLedger's engines needs none.
 */
@Composable
fun TranscriptionKeySection(vm: MainViewModel, plan: PlanState) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var key by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    SectionCard {
        Text("Transcription key", fontWeight = FontWeight.SemiBold, color = Brand.Text)
        if (!plan.needsOwnKey && plan.transcriptionKey?.set != true) {
            Text("Your plan transcribes on EchoLedger's engines: you don't need a key.", color = Brand.Muted)
            return@SectionCard
        }
        val saved = plan.transcriptionKey?.takeIf { it.set }
        if (saved != null) {
            Text("Your Groq key ending in ${saved.last4 ?: "····"} is saved. New recordings are transcribed with it.", color = Brand.Text)
            EchoButton("Remove key", { scope.launch { vm.removeTranscriptionKey() } }, kind = ButtonKind.Text)
        } else {
            Text("The Free plan transcribes with your own free Groq key. It takes two minutes:", color = Brand.Text)
            Text("1. Open console.groq.com and sign in (free).", color = Brand.Text)
            Text("2. API Keys → Create API key, then copy it.", color = Brand.Text)
            Text("3. Paste it here and tap Save.", color = Brand.Text)
            EchoButton("Open console.groq.com", {
                runCatching {
                    context.startActivity(
                        Intent(Intent.ACTION_VIEW, Uri.parse("https://console.groq.com/keys")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    )
                }
            }, kind = ButtonKind.Secondary)
        }
        OutlinedTextField(
            key,
            { key = it.trim() },
            label = { Text(if (saved != null) "Replace with a new key" else "Groq API key") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth()
        )
        EchoButton("Save", {
            saving = true
            scope.launch {
                if (vm.saveTranscriptionKey(key)) key = ""
                saving = false
            }
        }, Modifier.fillMaxWidth(), enabled = key.length >= 20, loading = saving)
        Text("Your key is encrypted on the server and used only for your own recordings.", color = Brand.Muted, fontSize = 12.sp)
    }
}
