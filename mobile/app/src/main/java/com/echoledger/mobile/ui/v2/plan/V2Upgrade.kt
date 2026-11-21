package com.echoledger.mobile.ui.v2.plan

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Intent
import android.graphics.BitmapFactory
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextDirection
import com.echoledger.mobile.R
import com.echoledger.mobile.core.PlanFormat
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.core.Receipts
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.PromoCheckDto
import com.echoledger.mobile.data.ProofDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.ui.FlowStep
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.nameOf
import com.echoledger.mobile.ui.resumeStep
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoDivider
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoProgressRing
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.VmMessages
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.launch

/** "20 Nov 2026" in the screen's language with 0-9 digits; null for no date. */
private fun day(iso: String?, locale: Locale): String? = PlanFormat.calendarDay(iso)?.let {
    LibraryModel.latinDigits(it.format(DateTimeFormatter.ofPattern("d MMM yyyy", locale)))
}

/** "20 Nov 2026, 14:05" (or 2:05 pm): when a step of the request happened. */
private fun stamp(iso: String?, locale: Locale, is24: Boolean): String? {
    val at = PlanFormat.parse(iso)?.atZone(ZoneId.systemDefault()) ?: return null
    val time = at.format(DateTimeFormatter.ofPattern(if (is24) "HH:mm" else "h:mm a", locale))
    return LibraryModel.latinDigits(listOfNotNull(day(iso, locale), time).joinToString(", "))
}

/** The amount to send: "Rs 1,500", or the server's words for it. */
private fun amountOf(r: UpgradeRequestDto): String? = r.amountDuePkr?.let { PlanFormat.rupees(it) } ?: r.amountDue

/**
 * Upgrading in the new design (classic's UpgradeFlow, the same calls and steps): choose a plan above yours (and a promo
 * code) → pay by bank transfer, every detail copyable → send the receipt (screenshots or a PDF, the transaction ID) →
 * the request's status with its timeline, Cancel request and Request again. An open request opens where it stands;
 * a 100 % code switches the plan on at once. Errors stay in the step, under its button.
 */
@Composable
fun V2UpgradeFlow(
    vm: MainViewModel,
    startPlan: String?,
    feature: String?,
    startAt: FlowStep?,
    given: PlanState? = null,
    /** This build sells plans by bank transfer (classic's manualPayments: the Google Play build does not). */
    canPay: Boolean = com.echoledger.mobile.ui.manualPayments,
    onDismiss: () -> Unit
) {
    val settings by vm.settings.collectAsState()
    val live = settings.plan
    val plan = if (live.offers.isEmpty() && given != null) given.copy(upgrade = live.upgrade ?: given.upgrade) else live
    // As classic's canBuy: a team member or viewer, or the Google Play build, sees the plans without prices and buys nothing.
    val member = plan.role == "member" || plan.role == "viewer"
    if (!canPay || member) {
        PlansWithoutPrices(plan, feature, member, onDismiss)
        return
    }
    val request = plan.upgrade
    var chosen by remember { mutableStateOf<FlowStep?>(null) }
    val step = chosen ?: when {
        startAt == FlowStep.Choose -> FlowStep.Choose
        request != null && request.open -> startAt ?: request.resumeStep()
        startAt != null && request != null -> startAt
        else -> FlowStep.Choose
    }
    var history by remember { mutableStateOf(listOf<FlowStep>()) }
    val go: (FlowStep) -> Unit = { next ->
        history = history + step
        chosen = next
    }
    val back: () -> Unit = {
        if (history.isEmpty()) {
            onDismiss()
        } else {
            chosen = history.last()
            history = history.dropLast(1)
        }
    }
    LaunchedEffect(Unit) { vm.loadUpgradeRequest() }
    BackHandler(onBack = back)
    val planName = plan.nameOf(if (step == FlowStep.Choose) startPlan else request?.plan ?: startPlan)
    // Drawn edge to edge: the pinned bottom bar stays above the navigation bar and the keyboard.
    Column(Modifier.fillMaxSize().background(EchoV2.colors.bgCanvas).navigationBarsPadding().imePadding()) {
        EchoTopBar(
            when (step) {
                FlowStep.Choose -> stringResource(R.string.v2_up_title)
                FlowStep.Pay -> stringResource(R.string.v2_up_pay_title, planName)
                FlowStep.Receipt -> stringResource(R.string.v2_up_receipt_title)
                FlowStep.Status -> stringResource(R.string.v2_up_status_title, planName)
                FlowStep.Approved -> ""
            },
            onBack = back
        )
        when (step) {
            FlowStep.Choose -> ChooseStep(vm, plan, startPlan, feature) { answer ->
                if (answer.status == "approved") {
                    history = emptyList()
                    chosen = FlowStep.Approved
                } else {
                    go(FlowStep.Pay)
                }
            }

            FlowStep.Pay -> request?.let { PayStep(it, planName, onReceipt = { go(FlowStep.Receipt) }, onLater = onDismiss) }
                ?: Missing(onDismiss)

            FlowStep.Receipt -> request?.let { ReceiptStep(vm, it) { go(FlowStep.Status) } } ?: Missing(onDismiss)

            FlowStep.Status -> request?.let { StatusStep(vm, it, planName, go) } ?: Missing(onDismiss)

            FlowStep.Approved -> ApprovedStep(plan, request, onDismiss)
        }
    }
}

/**
 * The plans as a team member, a viewer or the Google Play build sees them (classic's rule): which plan this is and who
 * manages it, then the plans above it with what each adds, never a price or anything to buy.
 */
@Composable
private fun PlansWithoutPrices(plan: PlanState, feature: String?, member: Boolean, onDismiss: () -> Unit) {
    val c = EchoV2.colors
    BackHandler(onBack = onDismiss)
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    Column(Modifier.fillMaxSize().background(c.bgCanvas).navigationBarsPadding()) {
        EchoTopBar(stringResource(R.string.v2_up_plans), onBack = onDismiss)
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            Text(
                if (member) {
                    stringResource(R.string.v2_up_member, plan.workspace ?: stringResource(R.string.v2_up_your_workspace), plan.displayName)
                } else {
                    stringResource(R.string.v2_up_you_are_on, plan.displayName)
                },
                style = EchoV2.type.body,
                color = c.textSecondary,
                modifier = pad
            )
            feature?.let { f ->
                plan.higherPlans.firstOrNull()?.let {
                    Text(
                        stringResource(R.string.v2_up_feature_in, f, it.name),
                        style = EchoV2.type.body,
                        color = c.textPrimary,
                        modifier = pad
                    )
                }
            }
            val offers = plan.higherPlans
            offers.forEachIndexed { i, offer ->
                EchoCard(pad) {
                    Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                        Text(offer.name, style = EchoV2.type.bodyStrong, color = c.textPrimary)
                        if (i > 0) {
                            Text(
                                stringResource(R.string.v2_up_everything_in, offers[i - 1].name),
                                style = EchoV2.type.meta,
                                color = c.textSecondary
                            )
                        }
                        highlights(plan, offer).forEach { Text(it, style = EchoV2.type.body, color = c.textPrimary) }
                    }
                }
            }
            if (!member) {
                Text(
                    stringResource(R.string.v2_up_managed_outside),
                    style = EchoV2.type.meta,
                    color = c.textSecondary,
                    modifier = pad
                )
            }
        }
    }
}

@Composable
private fun Missing(onDismiss: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(EchoTokens.Space.s16), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
        Text(stringResource(R.string.v2_up_missing), style = EchoV2.type.body, color = EchoV2.colors.textSecondary)
        EchoButton(stringResource(R.string.v2_up_close), onDismiss, kind = EchoButtonKind.Secondary)
    }
}

/** A step's pinned bottom: its error, an optional line, an optional block beside the one filled button, then [extra]. */
@Composable
private fun BottomBar(
    caption: String?,
    error: String?,
    left: (@Composable () -> Unit)? = null,
    extra: (@Composable () -> Unit)? = null,
    button: @Composable () -> Unit
) {
    val c = EchoV2.colors
    Column(Modifier.fillMaxWidth().background(c.bgSurface)) {
        EchoDivider()
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            error?.let { EchoBanner(it, EchoBannerKind.Error) }
            caption?.let { Text(it, style = EchoV2.type.meta, color = c.textSecondary) }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                if (left != null) {
                    Box(Modifier.weight(1f)) { left() }
                    button()
                } else {
                    Box(Modifier.weight(1f)) { button() }
                }
            }
            extra?.invoke()
        }
    }
}

/** A plan card's lines: its minutes, team size and the paid features the plan below lacks (at most 5), in the screen's words. */
@Composable
private fun highlights(plan: PlanState, offer: PlanOfferDto): List<String> {
    val index = plan.offers.indexOf(offer)
    val below = plan.offers.getOrNull(index - 1)?.features.orEmpty().toSet()
    val out = mutableListOf<String>()
    offer.limits["monthly_minutes"]?.takeIf { it > 0 }?.let {
        out += stringResource(R.string.v2_up_minutes, "%,d".format(Locale.ENGLISH, it))
    }
    offer.limits["seats"]?.takeIf { it > 1 }?.let { out += stringResource(R.string.v2_up_team, it.toInt()) }
    offer.features.filter { it !in below }.forEach { key -> plan.features[key]?.name?.takeIf { it.isNotBlank() }?.let { out += it } }
    return out.distinct().take(5)
}

@Composable
private fun ChooseStep(vm: MainViewModel, plan: PlanState, startPlan: String?, feature: String?, onRequested: (UpgradeRequestDto) -> Unit) {
    val scope = rememberCoroutineScope()
    val res = LocalContext.current.resources
    val c = EchoV2.colors
    val offers = plan.higherPlans
    var selected by remember { mutableStateOf(offers.firstOrNull { it.key == startPlan }?.key ?: offers.firstOrNull()?.key) }
    var promo by remember { mutableStateOf<PromoCheckDto?>(null) }
    var promoOpen by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val chosen = offers.firstOrNull { it.key == selected } ?: offers.firstOrNull { it.key == startPlan } ?: offers.firstOrNull()
    val promoFits = promo != null && promo?.plan == chosen?.key
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    Column(Modifier.fillMaxSize()) {
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(top = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            Text(
                listOfNotNull(
                    feature?.let { f -> chosen?.let { stringResource(R.string.v2_up_feature_in, f, it.name) } },
                    stringResource(R.string.v2_up_you_are_on, plan.displayName)
                ).joinToString(" "),
                style = EchoV2.type.body,
                color = c.textSecondary,
                modifier = pad
            )
            if (!plan.isFree && plan.expiresAt != null && chosen != null) {
                Text(
                    stringResource(R.string.v2_up_no_refund, chosen.name, plan.displayName),
                    style = EchoV2.type.body,
                    color = c.textSecondary,
                    modifier = pad
                )
            }
            if (offers.isEmpty()) {
                Text(
                    stringResource(R.string.v2_up_top_plan),
                    style = EchoV2.type.body,
                    color = c.textPrimary,
                    modifier = pad
                )
            }
            offers.forEachIndexed { i, offer ->
                val on = offer.key == chosen?.key
                val price = PlanFormat.price(offer.priceText, offer.pricePkr, offer.price)
                val shape = RoundedCornerShape(EchoTokens.Component.cardRadius)
                Column(
                    pad.fillMaxWidth().clip(shape)
                        .background(if (on) c.accentSoft else c.bgSurface)
                        .border(
                            if (on) EchoTokens.Border.strong else EchoTokens.Border.hairline,
                            if (on) c.accent else c.borderDefault,
                            shape
                        )
                        .selectable(on, role = Role.RadioButton) {
                            selected = offer.key
                            error = null
                        }
                        .semantics { contentDescription = listOfNotNull(offer.name, price).joinToString(", ") }
                        .padding(EchoTokens.Space.s16),
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
                ) {
                    Text(offer.name, style = EchoV2.type.bodyStrong, color = if (on) c.accentText else c.textPrimary)
                    price?.let { Text(it, style = EchoV2.type.body, color = c.textSecondary) }
                    if (i >
                        0
                    ) {
                        Text(
                            stringResource(R.string.v2_up_everything_in, offers[i - 1].name),
                            style = EchoV2.type.meta,
                            color = c.textSecondary
                        )
                    }
                    highlights(plan, offer).forEach { Text(it, style = EchoV2.type.body, color = c.textPrimary) }
                }
            }
            if (offers.isNotEmpty()) {
                if (promoOpen && promo == null) {
                    PromoField(vm, chosen?.key) { applied ->
                        promoOpen = false
                        promo = applied
                        if (offers.any { it.key == applied.plan }) selected = applied.plan
                    }
                } else {
                    EchoSettingRow(
                        stringResource(R.string.v2_up_promo),
                        value = promo?.code ?: stringResource(R.string.v2_up_promo_add),
                        trailing = promo?.let {
                            {
                                EchoIconButton(EchoIcons.Close, stringResource(R.string.v2_up_promo_remove), { promo = null })
                            }
                        },
                        onClick = { if (promo == null) promoOpen = true }
                    )
                }
                promo?.let { p ->
                    val name = p.planName.ifBlank { plan.nameOf(p.plan) }
                    Text(
                        if (promoFits) {
                            pluralStringResource(R.plurals.v2_up_promo_off, p.months, p.code, p.percent, name, p.months)
                        } else {
                            stringResource(R.string.v2_up_promo_for, name)
                        },
                        style = EchoV2.type.meta,
                        color = if (promoFits) c.success else c.warning,
                        modifier = pad
                    )
                }
                EchoTextField(
                    note,
                    { note = it.take(500) },
                    stringResource(R.string.v2_up_note),
                    modifier = pad,
                    placeholder = stringResource(R.string.v2_up_note_hint),
                    singleLine = false
                )
            }
        }
        if (chosen != null) {
            val full = chosen.pricePkr
            val free = promoFits && promo?.free == true
            // What the code leaves to pay, from the plan's price in rupees (the server's words only without one).
            val due = promo?.takeIf { promoFits }?.let { p ->
                full?.let { PlanFormat.rupees(Math.round(it * (100 - p.percent) / 100.0)) }
                    ?: p.finalPrice?.substringBefore(" /")?.substringBefore(" a month")
            }
            BottomBar(
                caption = if (free) null else stringResource(R.string.v2_up_bank_next),
                error = error,
                left = {
                    Column {
                        Text(stringResource(R.string.v2_up_to_pay), style = EchoV2.type.meta, color = c.textSecondary)
                        if (due != null && full != null) {
                            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                                Text(if (free) PlanFormat.rupees(0) else due, style = EchoV2.type.titleS, color = c.textPrimary)
                                Text(
                                    PlanFormat.rupees(full),
                                    style = EchoV2.type.meta.copy(textDecoration = TextDecoration.LineThrough),
                                    color = c.textSecondary
                                )
                            }
                        } else {
                            Text(
                                full?.let { PlanFormat.rupees(it) } ?: PlanFormat.price(chosen.priceText, null, chosen.price).orEmpty(),
                                style = EchoV2.type.titleS,
                                color = c.textPrimary
                            )
                        }
                    }
                }
            ) {
                EchoButton(
                    stringResource(if (free) R.string.v2_up_switch_on else R.string.v2_up_request, chosen.name),
                    {
                        sending = true
                        error = null
                        scope.launch {
                            val result = vm.requestUpgradeAttempt(chosen.key, note.ifBlank { null }, promo?.code?.takeIf { promoFits })
                            sending = false
                            result.value?.let(onRequested) ?: run { error = result.error?.let { VmMessages.localize(res, it) } }
                        }
                    },
                    loading = sending
                )
            }
        }
    }
}

/** The promo code, typed in place: capitals, digits and "-", Apply from 6 characters, a code for another plan moves to it. */
@Composable
private fun PromoField(vm: MainViewModel, plan: String?, onApplied: (PromoCheckDto) -> Unit) {
    val scope = rememberCoroutineScope()
    val res = LocalContext.current.resources
    var code by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var checking by remember { mutableStateOf(false) }
    val unsupported = stringResource(R.string.v2_up_promo_unsupported)
    val apply: () -> Unit = {
        checking = true
        error = null
        scope.launch {
            when (val r = vm.checkPromo(code, plan)) {
                is MainViewModel.PromoResult.Valid -> onApplied(r.promo)

                is MainViewModel.PromoResult.OtherPlan -> when (val again = vm.checkPromo(code, r.plan)) {
                    is MainViewModel.PromoResult.Valid -> onApplied(again.promo)
                    else -> error = VmMessages.localize(res, r.text)
                }

                is MainViewModel.PromoResult.Invalid -> error = VmMessages.localize(res, r.text)

                MainViewModel.PromoResult.Unsupported -> error = unsupported
            }
            checking = false
        }
    }
    Row(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        EchoTextField(
            code,
            { code = it.uppercase().filter { ch -> ch.isLetterOrDigit() || ch == '-' }.take(24) },
            stringResource(R.string.v2_up_promo),
            modifier = Modifier.weight(1f),
            error = error
        )
        EchoButton(
            stringResource(R.string.v2_up_apply),
            apply,
            enabled = code.length >= 6,
            loading = checking,
            kind = EchoButtonKind.Secondary
        )
    }
}

/** One copyable detail: its name, its value (left to right), and Copy, which says so. */
@Composable
private fun CopyRow(label: String, shown: String, copied: String = shown) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val toasts = LocalEchoToasts.current
    val done = stringResource(R.string.v2_up_copied, label)
    EchoSettingRow(
        label,
        subtitle = shown,
        content = true,
        trailing = {
            EchoIconButton(EchoIcons.Copy, stringResource(R.string.v2_up_copy, label), {
                context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText(label, copied))
                scope.launch { toasts.show(done) }
            })
        }
    )
}

@Composable
private fun PayStep(request: UpgradeRequestDto, planName: String, onReceipt: () -> Unit, onLater: () -> Unit) {
    val context = LocalContext.current
    val res = context.resources
    val c = EchoV2.colors
    val payment = request.payment
    val account = payment?.accounts?.firstOrNull()
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(top = EchoTokens.Space.s8)) {
            amountOf(request)?.let {
                CopyRow(
                    stringResource(R.string.v2_up_amount),
                    it,
                    request.amountDuePkr?.toString() ?: it.filter(Char::isDigit)
                )
            }
            Text(stringResource(R.string.v2_up_exact), style = EchoV2.type.meta, color = c.textSecondary, modifier = pad)
            if (payment?.accounts.isNullOrEmpty()) {
                val text = payment?.note ?: request.paymentInstructions
                if (!text.isNullOrBlank()) {
                    EchoSectionHeader(stringResource(R.string.v2_up_pay_to))
                    Text(text, style = EchoV2.type.content(text, EchoV2.type.body), color = c.textPrimary, modifier = pad)
                    CopyRow(stringResource(R.string.v2_up_all_details), stringResource(R.string.v2_up_all_details_help), text)
                }
            } else {
                payment?.accounts?.forEach { a ->
                    EchoSectionHeader(stringResource(R.string.v2_up_pay_to))
                    a.title?.let { CopyRow(stringResource(R.string.v2_up_account_title), it) }
                    a.iban?.let { CopyRow(stringResource(R.string.v2_up_iban), PlanFormat.groupIban(it), it.replace(" ", "")) }
                    a.accountNumber?.let { CopyRow(stringResource(R.string.v2_up_account_number), it, it.filter(Char::isDigit)) }
                    a.bank?.let { CopyRow(stringResource(R.string.v2_up_bank), listOfNotNull(it, a.branch).joinToString(", "), it) }
                }
            }
            request.referenceCode?.let { ref ->
                EchoSectionHeader(stringResource(R.string.v2_up_reference))
                CopyRow(stringResource(R.string.v2_up_reference), ref)
                Text(stringResource(R.string.v2_up_reference_help), style = EchoV2.type.meta, color = c.textSecondary, modifier = pad)
            }
            Column(pad.padding(vertical = EchoTokens.Space.s12), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                Text(stringResource(R.string.v2_up_any_bank), style = EchoV2.type.body, color = c.textPrimary)
                account?.title?.let {
                    Text(stringResource(R.string.v2_up_title_check, it), style = EchoV2.type.body, color = c.textPrimary)
                }
                if (!payment?.accounts.isNullOrEmpty()) {
                    payment?.note?.takeIf { it.isNotBlank() }?.let {
                        Text(it, style = EchoV2.type.content(it, EchoV2.type.body), color = c.textSecondary)
                    }
                }
                EchoButton(stringResource(R.string.v2_up_share_details), {
                    val text = buildString {
                        appendLine(res.getString(R.string.v2_up_share_head, planName))
                        amountOf(request)?.let { appendLine(res.getString(R.string.v2_up_amount) + ": " + it) }
                        account?.title?.let { appendLine(res.getString(R.string.v2_up_account_title) + ": " + it) }
                        account?.iban?.let { appendLine(res.getString(R.string.v2_up_iban) + ": " + it.replace(" ", "")) }
                        account?.accountNumber?.let { appendLine(res.getString(R.string.v2_up_account_number) + ": " + it) }
                        account?.bank?.let { appendLine(res.getString(R.string.v2_up_bank) + ": " + it) }
                        request.referenceCode?.let { appendLine(res.getString(R.string.v2_up_reference) + ": " + it) }
                        if (account == null) (payment?.note ?: request.paymentInstructions)?.let { appendLine(it) }
                    }.trim()
                    runCatching {
                        context.startActivity(
                            Intent.createChooser(
                                Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text),
                                res.getString(R.string.v2_up_share_details)
                            ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        )
                    }
                }, kind = EchoButtonKind.Quiet)
            }
        }
        BottomBar(
            caption = stringResource(R.string.v2_up_paid_add),
            error = null,
            extra = {
                EchoButton(
                    stringResource(R.string.v2_up_pay_later),
                    onLater,
                    kind = EchoButtonKind.Quiet,
                    modifier = Modifier.fillMaxWidth()
                )
            }
        ) {
            EchoButton(stringResource(R.string.v2_up_add_receipt), onReceipt, modifier = Modifier.fillMaxWidth())
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ReceiptStep(vm: MainViewModel, request: UpgradeRequestDto, onSent: () -> Unit) {
    val scope = rememberCoroutineScope()
    val res = LocalContext.current.resources
    val c = EchoV2.colors
    val proofs = request.payment?.proofs.orEmpty()
    val round = proofs.filter { !it.locked }
    val sent = proofs.filter { it.locked }
    var uploading by remember { mutableIntStateOf(0) }
    var error by remember { mutableStateOf<String?>(null) }
    var reference by remember { mutableStateOf(request.payment?.reference.orEmpty()) }
    var payer by remember { mutableStateOf(request.payment?.payerName.orEmpty()) }
    var sending by remember { mutableStateOf(false) }
    val room = (Receipts.PER_ROUND - round.size - uploading).coerceAtLeast(0)
    val upload: (List<android.net.Uri>) -> Unit = { uris ->
        val take = uris.take(room)
        if (take.isNotEmpty()) {
            error = null
            uploading += take.size
            scope.launch {
                take.forEach { uri ->
                    vm.uploadReceipt(request.id, uri).error?.let { error = VmMessages.localize(res, it) }
                    uploading -= 1
                }
            }
        }
    }
    val pickMany = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(Receipts.PER_ROUND)) { upload(it) }
    val pickOne = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> uri?.let { upload(listOf(it)) } }
    val pickPdf = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> uri?.let { upload(listOf(it)) } }
    val refOk = reference.trim().length >= 4
    Column(Modifier.fillMaxSize()) {
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState())
                .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            request.payment?.proofRequest?.note?.takeIf { request.stage == "needs_receipt" && it.isNotBlank() }?.let {
                EchoBanner(stringResource(R.string.v2_up_another, it), EchoBannerKind.Warning)
            }
            Text(stringResource(R.string.v2_up_receipt_help), style = EchoV2.type.body, color = c.textPrimary)
            if (sent.isNotEmpty() || round.isNotEmpty() || uploading > 0) {
                FlowRow(
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12),
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
                ) {
                    sent.forEach { ReceiptTile(vm, request.id, it, onRemove = null) }
                    round.forEachIndexed { i, p ->
                        ReceiptTile(vm, request.id, p, onRemove = {
                            scope.launch { vm.removeReceipt(request.id, p.id)?.let { error = VmMessages.localize(res, it) } }
                        }, index = i + 1)
                    }
                    repeat(uploading) { UploadingTile() }
                }
            }
            if (room > 0) {
                Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8), verticalAlignment = Alignment.CenterVertically) {
                    EchoButton(stringResource(R.string.v2_up_add_screenshot), {
                        val media = PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)
                        if (room >= 2) pickMany.launch(media) else pickOne.launch(media)
                    }, kind = EchoButtonKind.Secondary)
                    EchoButton(stringResource(R.string.v2_up_use_pdf), {
                        pickPdf.launch(arrayOf("application/pdf"))
                    }, kind = EchoButtonKind.Quiet)
                }
            } else {
                Text(stringResource(R.string.v2_up_three_at_a_time), style = EchoV2.type.meta, color = c.textSecondary)
            }
            EchoTextField(
                reference,
                { reference = it.filter { ch -> ch.isLetterOrDigit() || ch in " ._/#-" }.take(64) },
                stringResource(R.string.v2_up_tid),
                supportingText = stringResource(R.string.v2_up_tid_help)
            )
            EchoTextField(
                payer,
                { payer = it.take(120) },
                stringResource(R.string.v2_up_paid_from),
                supportingText = stringResource(R.string.v2_up_paid_from_help)
            )
            Text(stringResource(R.string.v2_up_private), style = EchoV2.type.meta, color = c.textSecondary)
        }
        BottomBar(caption = null, error = error) {
            EchoButton(
                stringResource(R.string.v2_up_send_receipt),
                {
                    sending = true
                    error = null
                    scope.launch {
                        val result = vm.sendReceipt(request.id, reference.ifBlank { null }, payer.ifBlank { null })
                        sending = false
                        if (result.value != null) onSent() else error = result.error?.let { VmMessages.localize(res, it) }
                    }
                },
                enabled = uploading == 0 && (round.isNotEmpty() || refOk),
                loading = sending,
                modifier = Modifier.fillMaxWidth()
            )
        }
    }
}

@Composable
private fun ReceiptTile(vm: MainViewModel, requestId: String, proof: ProofDto, onRemove: (() -> Unit)?, index: Int = 0) {
    val c = EchoV2.colors
    val locale = screenLocale()
    val bitmap by produceState<ImageBitmap?>(null, proof.id) {
        if (proof.kind == "image") {
            value =
                vm.receiptImage(requestId, proof.id)?.let { bytes -> BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap() }
        }
    }
    val shape = RoundedCornerShape(EchoTokens.Component.cardRadius)
    val receipt = stringResource(R.string.v2_up_receipt)
    Column {
        Box(
            Modifier.size(TILE).clip(shape).background(c.bgSubtle).border(EchoTokens.Border.hairline, c.borderDefault, shape)
                .semantics { contentDescription = proof.name ?: receipt }
        ) {
            val img = bitmap
            when {
                img != null -> Image(img, null, Modifier.fillMaxSize(), contentScale = ContentScale.Crop)

                proof.kind == "pdf" -> Column(
                    Modifier.fillMaxSize().padding(EchoTokens.Space.s8),
                    verticalArrangement = Arrangement.Center,
                    horizontalAlignment = Alignment.CenterHorizontally
                ) {
                    Icon(EchoIcons.Export, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
                    Text(proof.name ?: receipt, style = EchoV2.type.caption, color = c.textSecondary, maxLines = 1)
                    Text(PlanFormat.fileSize(proof.bytes), style = EchoV2.type.caption, color = c.textSecondary)
                }

                else -> Icon(EchoIcons.Screens, null, Modifier.size(EchoTokens.Icon.md).align(Alignment.Center), tint = c.textSecondary)
            }
            if (onRemove != null) {
                Box(Modifier.align(Alignment.TopEnd)) {
                    EchoIconButton(EchoIcons.Close, stringResource(R.string.v2_up_remove_receipt, index), onRemove)
                }
            }
        }
        if (onRemove == null) {
            Text(
                day(proof.createdAt, locale)?.let { stringResource(R.string.v2_up_sent_on, it) } ?: stringResource(R.string.v2_up_sent),
                style = EchoV2.type.caption,
                color = c.textSecondary,
                modifier = Modifier.padding(top = EchoTokens.Space.s4)
            )
        }
    }
}

@Composable
private fun UploadingTile() {
    val label = stringResource(R.string.v2_up_uploading)
    Box(
        Modifier.size(TILE).clip(RoundedCornerShape(EchoTokens.Component.cardRadius)).background(EchoV2.colors.bgSubtle)
            .semantics { contentDescription = label },
        contentAlignment = Alignment.Center
    ) { EchoProgressRing(null, size = EchoTokens.Icon.lg) }
}

/** The request's card: its title, the tag (waiting, in review, action needed…) and what to do next, in the screen's words. */
private data class Card(val title: String, val tag: String, val tone: EchoStatusKind, val body: String)

@Composable
private fun card(r: UpgradeRequestDto, planName: String, is24: Boolean): Card {
    val res = LocalContext.current.resources
    val locale = screenLocale()
    val amount = amountOf(r) ?: res.getString(R.string.v2_up_the_amount)
    return when (r.stage) {
        "awaiting_payment" -> Card(
            res.getString(R.string.v2_up_c_requested, planName),
            res.getString(R.string.v2_up_t_waiting),
            EchoStatusKind.Neutral,
            res.getString(R.string.v2_up_b_waiting, amount) +
                (r.referenceCode?.let { " " + res.getString(R.string.v2_up_b_reference, it) } ?: "")
        )

        "in_review" -> Card(
            res.getString(R.string.v2_up_c_checking),
            res.getString(R.string.v2_up_t_review),
            EchoStatusKind.Neutral,
            listOfNotNull(
                stamp(r.payment?.paidAt, locale, is24)?.let { res.getString(R.string.v2_up_b_got_on, it) }
                    ?: res.getString(R.string.v2_up_b_got),
                r.payment?.reviewHours
            ).joinToString(" ")
        )

        "needs_receipt" -> Card(
            res.getString(R.string.v2_up_c_another),
            res.getString(R.string.v2_up_t_action),
            EchoStatusKind.Warning,
            listOfNotNull(r.payment?.proofRequest?.note?.takeIf { it.isNotBlank() }, res.getString(R.string.v2_up_b_still_open))
                .joinToString("\n\n")
        )

        "declined" -> Card(
            res.getString(R.string.v2_up_c_declined),
            res.getString(R.string.v2_up_t_declined),
            EchoStatusKind.Neutral,
            listOfNotNull(r.reply?.takeIf { it.isNotBlank() }, res.getString(R.string.v2_up_b_declined)).joinToString("\n\n")
        )

        "cancelled" -> Card(
            res.getString(R.string.v2_up_c_cancelled),
            res.getString(R.string.v2_up_t_cancelled),
            EchoStatusKind.Neutral,
            res.getString(R.string.v2_up_b_cancelled)
        )

        else -> Card(
            res.getString(R.string.v2_up_on, planName),
            res.getString(R.string.v2_up_t_approved),
            EchoStatusKind.Success,
            res.getString(R.string.v2_up_thanks) +
                (day(r.approvedUntil, locale)?.let { " " + res.getString(R.string.v2_up_on_until, planName, it) } ?: "")
        )
    }
}

@Composable
private fun StatusStep(vm: MainViewModel, request: UpgradeRequestDto, planName: String, go: (FlowStep) -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val res = context.resources
    val locale = screenLocale()
    val c = EchoV2.colors
    val is24 = android.text.format.DateFormat.is24HourFormat(context)
    var confirmCancel by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val copy = card(request, planName, is24)
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(vertical = EchoTokens.Space.s8),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        EchoCard(pad) {
            Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(copy.title, style = EchoV2.type.bodyStrong, color = c.textPrimary, modifier = Modifier.weight(1f))
                    EchoStatus(copy.tag, copy.tone)
                }
                Text(copy.body, style = EchoV2.type.content(copy.body, EchoV2.type.body), color = c.textSecondary)
            }
        }
        EchoCard(pad) { Timeline(request, planName, is24) }
        EchoSectionHeader(stringResource(R.string.v2_up_details))
        EchoSettingRow(stringResource(R.string.v2_up_plan), value = planName)
        amountOf(request)?.let { EchoSettingRow(stringResource(R.string.v2_up_amount), value = it) }
        request.promoCode?.let { EchoSettingRow(stringResource(R.string.v2_up_promo), value = it) }
        day(request.createdAt, locale)?.let { EchoSettingRow(stringResource(R.string.v2_up_requested), value = it) }
        request.payment?.reference?.let { EchoSettingRow(stringResource(R.string.v2_up_tid_short), value = it) }
        request.referenceCode?.let { CopyRow(stringResource(R.string.v2_up_reference), it) }
        val proofs = request.payment?.proofs.orEmpty()
        if (proofs.isNotEmpty()) {
            EchoSectionHeader(stringResource(R.string.v2_up_receipts))
            Row(pad, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                proofs.forEach { ReceiptTile(vm, request.id, it, onRemove = null) }
            }
        }
        error?.let { EchoBanner(it, EchoBannerKind.Error, pad) }
        Column(pad, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            if (request.stage == "awaiting_payment" || request.stage == "needs_receipt") {
                EchoButton(stringResource(R.string.v2_up_add_receipt_short), { go(FlowStep.Receipt) }, modifier = Modifier.fillMaxWidth())
            }
            if (request.open) {
                EchoButton(
                    stringResource(R.string.v2_up_payment_details),
                    { go(FlowStep.Pay) },
                    kind = EchoButtonKind.Secondary,
                    modifier = Modifier.fillMaxWidth()
                )
                EchoButton(stringResource(R.string.v2_up_cancel_request), { confirmCancel = true }, kind = EchoButtonKind.Quiet)
            }
            if (request.stage == "declined" || request.stage == "cancelled") {
                EchoButton(
                    stringResource(R.string.v2_up_request_again),
                    { go(FlowStep.Choose) },
                    kind = EchoButtonKind.Secondary,
                    modifier = Modifier.fillMaxWidth()
                )
            }
        }
    }
    if (confirmCancel) {
        EchoDialog(
            title = stringResource(R.string.v2_up_cancel_title),
            text = stringResource(R.string.v2_up_cancel_text),
            confirmLabel = stringResource(R.string.v2_up_cancel_request),
            onConfirm = {
                confirmCancel = false
                scope.launch { vm.cancelUpgradeRequest(request.id).error?.let { error = VmMessages.localize(res, it) } }
            },
            dismissLabel = stringResource(R.string.v2_up_keep_request),
            onDismiss = { confirmCancel = false },
            destructive = true
        )
    }
}

/** Requested → Receipt sent → Checked by our team → {Plan} on; a closed request ends in one final step. */
@Composable
private fun Timeline(request: UpgradeRequestDto, planName: String, is24: Boolean) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    val locale = screenLocale()
    fun at(step: String) = request.timeline.lastOrNull { it.step == step }?.at
    val sentAt = at("receipt_sent") ?: request.payment?.paidAt
    val decidedAt = at("decided") ?: request.decidedAt
    data class Step(val label: String, val done: Boolean, val current: Boolean, val time: String?, val note: String? = null)
    val steps = buildList {
        add(Step(res.getString(R.string.v2_up_s_requested), true, false, stamp(at("requested") ?: request.createdAt, locale, is24)))
        val asked = request.stage == "needs_receipt"
        add(
            Step(
                res.getString(R.string.v2_up_s_sent),
                sentAt != null && !asked,
                request.stage == "awaiting_payment" || asked,
                stamp(sentAt, locale, is24)
            )
        )
        if (asked) {
            add(
                Step(
                    res.getString(R.string.v2_up_s_asked),
                    true,
                    false,
                    stamp(request.payment?.proofRequest?.at, locale, is24),
                    request.payment?.proofRequest?.note
                )
            )
        }
        when (request.stage) {
            "declined" -> add(Step(res.getString(R.string.v2_up_s_declined), true, false, stamp(decidedAt, locale, is24)))

            "cancelled" -> add(
                Step(res.getString(R.string.v2_up_s_cancelled), true, false, stamp(at("cancelled") ?: decidedAt, locale, is24))
            )

            else -> {
                val approved = request.stage == "approved"
                add(Step(res.getString(R.string.v2_up_s_checked), approved, request.stage == "in_review", stamp(decidedAt, locale, is24)))
                add(
                    Step(
                        res.getString(R.string.v2_up_on_short, planName),
                        approved,
                        false,
                        day(request.approvedUntil, locale)?.let { res.getString(R.string.v2_up_until, it) }
                    )
                )
            }
        }
    }
    Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
        steps.forEach { s ->
            val state = res.getString(
                when {
                    s.done -> R.string.v2_up_s_done
                    s.current -> R.string.v2_up_s_current
                    else -> R.string.v2_up_s_not_yet
                }
            )
            Row(
                Modifier.semantics(mergeDescendants = true) {
                    contentDescription = listOfNotNull(s.label, state, s.time?.takeIf { s.done || s.current }).joinToString(", ")
                },
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
            ) {
                Box(
                    Modifier.size(EchoTokens.Icon.md).clip(CircleShape).then(
                        when {
                            s.done -> Modifier.background(c.textPrimary)
                            s.current -> Modifier.border(EchoTokens.Border.strong, c.accent, CircleShape)
                            else -> Modifier.border(EchoTokens.Border.hairline, c.borderStrong, CircleShape)
                        }
                    ),
                    contentAlignment = Alignment.Center
                ) {
                    if (s.done) Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.sm), tint = c.bgSurface)
                }
                Column {
                    Text(s.label, style = EchoV2.type.body, color = if (s.done || s.current) c.textPrimary else c.textSecondary)
                    s.time?.takeIf { s.done || s.current }?.let {
                        Text(it, style = EchoV2.type.meta.copy(textDirection = TextDirection.Content), color = c.textSecondary)
                    }
                    s.note?.let { Text(it, style = EchoV2.type.content(it, EchoV2.type.meta), color = c.textSecondary) }
                }
            }
        }
    }
}

@Composable
private fun ApprovedStep(plan: PlanState, request: UpgradeRequestDto?, onDone: () -> Unit) {
    val c = EchoV2.colors
    val locale = screenLocale()
    val name = plan.nameOf(request?.approvedPlan ?: request?.plan)
    Column(
        Modifier.fillMaxSize().padding(EchoTokens.Space.s24),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Space.s48), tint = c.success)
        Text(stringResource(R.string.v2_up_on, name), style = EchoV2.type.titleS, color = c.textPrimary)
        Text(
            stringResource(R.string.v2_up_thanks) +
                (day(request?.approvedUntil, locale)?.let { " " + stringResource(R.string.v2_up_on_until, name, it) } ?: ""),
            style = EchoV2.type.body,
            color = c.textSecondary
        )
        EchoButton(stringResource(R.string.v2_up_done), onDone, modifier = Modifier.fillMaxWidth())
    }
}

private val TILE = EchoTokens.Space.s64 + EchoTokens.Space.s32
