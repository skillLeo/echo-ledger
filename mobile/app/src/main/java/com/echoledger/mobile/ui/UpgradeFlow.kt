package com.echoledger.mobile.ui

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
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.PictureAsPdf
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
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
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.echoledger.mobile.core.PlanFormat
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.core.Receipts
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.PromoCheckDto
import com.echoledger.mobile.data.ProofDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.CopyRow
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.PlanTag
import com.echoledger.mobile.ui.design.RowDivider
import com.echoledger.mobile.ui.design.SettingsSection
import com.echoledger.mobile.ui.design.SuccessCheck
import kotlinx.coroutines.launch

/** The steps of the upgrade flow (since v1.7, docs/DESIGN_SYSTEM.md "Settings"). */
enum class FlowStep { Choose, Pay, Receipt, Status, Approved }

/** Where an open request picks up: payment details while it waits for the money, a new receipt when asked, else its status. */
internal fun UpgradeRequestDto.resumeStep(): FlowStep = when (stage) {
    "awaiting_payment" -> FlowStep.Pay
    "needs_receipt" -> FlowStep.Receipt
    "approved" -> FlowStep.Approved
    else -> FlowStep.Status
}

/**
 * Upgrading, full screen with a pinned bottom bar: choose a plan (and a promo code) → pay by bank transfer with every
 * detail copyable → send the receipt (screenshots or a PDF, and the transaction ID) → the request's status. An open
 * request opens where it stands; errors stay in the flow under the button.
 */
@Composable
fun UpgradeFlow(
    vm: MainViewModel,
    startPlan: String?,
    feature: String?,
    startAt: FlowStep?,
    given: PlanState? = null,
    /** False in screen tests: the flow draws in place instead of in its full-screen window. */
    inDialog: Boolean = true,
    onDismiss: () -> Unit
) {
    val settings by vm.settings.collectAsState()
    val live = settings.plan
    // The plan the caller drew (a locked row's) when this phone has not stored one yet; the stored request always wins.
    val plan = if (live.offers.isEmpty() && given != null) given.copy(upgrade = live.upgrade ?: given.upgrade) else live
    val request = plan.upgrade
    // Until the person moves, the first step follows the request as it loads (a stored one may arrive a moment later).
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
    val body: @Composable () -> Unit = {
        Surface(Modifier.fillMaxSize(), color = Brand.Background) {
            // Its own window draws behind the bars and the keyboard, so it pads for them; drawn in place it does not.
            Column(
                Modifier.fillMaxSize().then(if (inDialog) Modifier.statusBarsPadding().navigationBarsPadding().imePadding() else Modifier)
            ) {
                BackHandler(onBack = back)
                val planName = plan.nameOf(if (step == FlowStep.Choose) startPlan else request?.plan ?: startPlan)
                com.echoledger.mobile.ui.design.EchoTopBar(
                    when (step) {
                        FlowStep.Choose -> "Upgrade"
                        FlowStep.Pay -> "Pay for $planName"
                        FlowStep.Receipt -> "Send receipt"
                        FlowStep.Status -> "$planName request"
                        FlowStep.Approved -> ""
                    },
                    large = false,
                    navigation = {
                        com.echoledger.mobile.ui.design.BarIcon(
                            if (history.isEmpty()) Icons.Rounded.Close else Icons.AutoMirrored.Rounded.ArrowBack,
                            if (history.isEmpty()) "Close" else "Back",
                            back,
                            tint = Brand.Text
                        )
                    }
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

                    FlowStep.Status -> request?.let { StatusStep(vm, plan, it, planName, go) } ?: Missing(onDismiss)

                    FlowStep.Approved -> ApprovedStep(plan, request, onDismiss)
                }
            }
        }
    }
    if (inDialog) {
        Dialog(onDismissRequest = back, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
            body()
        }
    } else {
        body()
    }
}

@Composable
private fun Missing(onDismiss: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
        Text(
            "This request isn't here any more. Open Plan & usage to see where it stands.",
            style = LocalEchoType.current.body,
            color = Brand.Muted
        )
        EchoButton("Close", onDismiss, kind = ButtonKind.Secondary)
    }
}

/** The pinned bottom bar of a step: an optional block on the left and the step's one filled button. */
@Composable
private fun BottomBar(
    caption: String?,
    left: (@Composable () -> Unit)?,
    error: String?,
    button: @Composable () -> Unit,
    extra: (@Composable () -> Unit)? = null
) {
    Column(Modifier.fillMaxWidth().background(Brand.Surface)) {
        HorizontalDivider(thickness = 1.dp, color = Brand.Border)
        Column(Modifier.padding(horizontal = Space.screen, vertical = Space.s3), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
            if (error != null) InlineBanner(error, kind = BannerKind.Error, onDismiss = null)
            if (caption !=
                null
            ) {
                Text(caption, style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.Normal), color = Brand.Muted)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (left != null) {
                    Box(Modifier.weight(1f)) { left() }
                    Spacer(Modifier.size(Space.s3))
                    button()
                } else {
                    Box(Modifier.weight(1f)) { button() }
                }
            }
            extra?.invoke()
        }
    }
}

@Composable
private fun ChooseStep(vm: MainViewModel, plan: PlanState, startPlan: String?, feature: String?, onRequested: (UpgradeRequestDto) -> Unit) {
    val scope = rememberCoroutineScope()
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val offers = plan.higherPlans
    var selected by remember { mutableStateOf(offers.firstOrNull { it.key == startPlan }?.key ?: offers.firstOrNull()?.key) }
    var promo by remember { mutableStateOf<PromoCheckDto?>(null) }
    var promoOpen by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // The plans may arrive after the flow opened: fall back to the plan it was opened for, then the next one up.
    val chosen = offers.firstOrNull { it.key == selected } ?: offers.firstOrNull { it.key == startPlan } ?: offers.firstOrNull()
    val promoFits = promo != null && promo?.plan == chosen?.key
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(top = Space.s2)) {
            Text(
                listOfNotNull(
                    feature?.let { f -> chosen?.let { "$f is in ${it.name}." } },
                    "You're on ${plan.displayName}."
                ).joinToString(" "),
                style = type.body,
                color = c.textMuted,
                modifier = Modifier.padding(horizontal = Space.screen).padding(bottom = Space.s3)
            )
            if (!plan.isFree && plan.expiresAt != null && chosen != null) {
                Text(
                    "${chosen.name} starts when we approve your payment. Days left on ${plan.displayName} aren't refunded.",
                    style = type.body,
                    color = c.textMuted,
                    modifier = Modifier.padding(horizontal = Space.screen).padding(bottom = Space.s3)
                )
            }
            if (offers.isEmpty()) {
                Text("You're on our top plan.", style = type.body, color = c.text, modifier = Modifier.padding(horizontal = Space.screen))
            }
            offers.forEachIndexed { i, offer ->
                val on = offer.key == chosen?.key
                val price = PlanFormat.price(offer.priceText, offer.pricePkr, offer.price)
                Column(
                    Modifier.fillMaxWidth().padding(
                        horizontal = Space.screen
                    ).padding(bottom = Space.s3).clip(RoundedCornerShape(Radius.md))
                        .background(if (on) c.accentSoft else c.surface)
                        .border(if (on) 2.dp else 1.dp, if (on) c.accent else c.border, RoundedCornerShape(Radius.md))
                        .selectable(on, role = Role.RadioButton) {
                            selected = offer.key
                            error = null
                        }
                        .semantics { contentDescription = listOfNotNull(offer.name, price).joinToString(", ") }
                        .padding(Space.s4),
                    verticalArrangement = Arrangement.spacedBy(Space.s1)
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        RadioButton(selected = on, onClick = null)
                        Spacer(Modifier.size(Space.s2))
                        Text(
                            offer.name,
                            style = type.bodyL.copy(fontWeight = FontWeight.SemiBold),
                            color = if (on) c.accentSoftText else c.text
                        )
                    }
                    price?.let { Text(it, style = type.body, color = c.textMuted, modifier = Modifier.padding(start = 40.dp)) }
                    Column(Modifier.padding(start = 40.dp, top = Space.s1), verticalArrangement = Arrangement.spacedBy(Space.s1)) {
                        if (i > 0) Text("Everything in ${offers[i - 1].name}, plus:", style = type.body, color = c.textMuted)
                        plan.highlights(offer).forEach { Text(it, style = type.body, color = c.text) }
                    }
                }
            }
            if (offers.isNotEmpty()) {
                SettingsSection(null) {
                    if (promoOpen && promo == null) {
                        PromoField(vm, chosen?.key) { applied ->
                            promoOpen = false
                            promo = applied
                            if (offers.any { it.key == applied.plan }) selected = applied.plan
                        }
                    } else {
                        com.echoledger.mobile.ui.design.SettingsRow(
                            null,
                            "Promo code",
                            value = promo?.code ?: "Add",
                            onClick = { if (promo == null) promoOpen = true else promo = null },
                            trailing = {
                                if (promo ==
                                    null
                                ) {
                                    Chevron()
                                } else {
                                    Icon(
                                        Icons.Rounded.Close,
                                        contentDescription = "Remove the code",
                                        tint = c.textMuted,
                                        modifier = Modifier.size(20.dp)
                                    )
                                }
                            }
                        )
                    }
                }
                promo?.let { p ->
                    Text(
                        if (promoFits) {
                            "${p.code}: ${p.percent}% off ${p.planName.ifBlank { plan.nameOf(p.plan) }}" +
                                if (p.months > 1) " for ${p.months} months" else " for 1 month"
                        } else {
                            "This code is for ${p.planName.ifBlank { plan.nameOf(p.plan) }}."
                        },
                        style = type.labelM.copy(fontWeight = FontWeight.Normal),
                        color = if (promoFits) c.success else c.warning,
                        modifier = Modifier.padding(horizontal = Space.screen + Space.s1).padding(bottom = Space.s3)
                    )
                }
                OutlinedTextField(
                    note,
                    { note = it.take(500) },
                    label = { Text("Note for our team (optional)") },
                    placeholder = { Text("For example, an invoice in my company's name") },
                    modifier = Modifier.fillMaxWidth().padding(horizontal = Space.screen).padding(bottom = Space.s4),
                    minLines = 2
                )
            }
        }
        if (chosen != null) {
            val full = chosen.pricePkr
            val free = promoFits && promo?.free == true
            val due = if (promoFits) promo?.finalPrice?.replace(" / month", "")?.replace(" a month", "") else null
            BottomBar(
                caption = if (free) null else "You'll see our bank details on the next screen.",
                left = {
                    Column {
                        Text("To pay", style = type.labelM.copy(fontWeight = FontWeight.Normal), color = c.textMuted)
                        if (due != null && full != null) {
                            Row(verticalAlignment = Alignment.Bottom) {
                                Text(if (free) "Rs 0" else due, style = type.titleM, color = c.text)
                                Spacer(Modifier.size(Space.s2))
                                Text(
                                    PlanFormat.rupees(full),
                                    style = type.labelM.copy(textDecoration = TextDecoration.LineThrough),
                                    color = c.textMuted
                                )
                            }
                        } else {
                            Text(
                                full?.let {
                                    PlanFormat.rupees(it)
                                } ?: PlanFormat.price(chosen.priceText, null, chosen.price) ?: "",
                                style = type.titleM,
                                color = c.text
                            )
                        }
                    }
                },
                error = error,
                button = {
                    EchoButton(if (free) "Switch on ${chosen.name}" else "Request ${chosen.name}", {
                        sending = true
                        error = null
                        scope.launch {
                            val result = vm.requestUpgradeAttempt(chosen.key, note.ifBlank { null }, promo?.code?.takeIf { promoFits })
                            sending = false
                            result.value?.let(onRequested) ?: run { error = result.error }
                        }
                    }, loading = sending)
                }
            )
        }
    }
}

/** The promo code, typed in place (since v1.7): capitals, digits and "-", Apply from 6 characters, the error under it. */
@Composable
private fun PromoField(vm: MainViewModel, plan: String?, onApplied: (PromoCheckDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var code by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var checking by remember { mutableStateOf(false) }
    val apply: () -> Unit = {
        checking = true
        error = null
        scope.launch {
            when (val r = vm.checkPromo(code, plan)) {
                is MainViewModel.PromoResult.Valid -> onApplied(r.promo)

                is MainViewModel.PromoResult.OtherPlan -> {
                    // A code for another plan: apply it to that plan.
                    when (val again = vm.checkPromo(code, r.plan)) {
                        is MainViewModel.PromoResult.Valid -> onApplied(again.promo)
                        else -> error = r.text
                    }
                }

                is MainViewModel.PromoResult.Invalid -> error = r.text

                MainViewModel.PromoResult.Unsupported -> error = "Promo codes aren't available right now."
            }
            checking = false
        }
    }
    Column(
        Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s3),
        verticalArrangement = Arrangement.spacedBy(Space.s2)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                code,
                { code = it.uppercase().filter { ch -> ch.isLetterOrDigit() || ch == '-' }.take(24) },
                label = { Text("Promo code") },
                singleLine = true,
                isError = error != null,
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters, imeAction = ImeAction.Done),
                keyboardActions = androidx.compose.foundation.text.KeyboardActions(onDone = { if (code.length >= 6 && !checking) apply() }),
                modifier = Modifier.weight(1f)
            )
            Spacer(Modifier.size(Space.s2))
            EchoButton("Apply", apply, enabled = code.length >= 6, loading = checking, kind = ButtonKind.Secondary)
        }
        error?.let {
            Text(it, color = LocalEchoColors.current.danger, style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.Normal))
        }
    }
}

@Composable
private fun PayStep(request: UpgradeRequestDto, planName: String, onReceipt: () -> Unit, onLater: () -> Unit) {
    val context = LocalContext.current
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val payment = request.payment
    val amount = request.amountDuePkr
    val account = payment?.accounts?.firstOrNull()
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(top = Space.s2)) {
            SettingsSection(null, footer = "Send exactly this amount. A different amount can slow down your upgrade.") {
                if (amount != null) {
                    CopyRow("Amount", PlanFormat.rupees(amount), amount.toString())
                } else {
                    request.amountDue?.let { CopyRow("Amount", it, it.filter(Char::isDigit)) }
                }
            }
            if (payment?.accounts.isNullOrEmpty()) {
                // An older or not yet set up server: its own written instructions.
                val text = payment?.note ?: request.paymentInstructions
                if (!text.isNullOrBlank()) {
                    SettingsSection("Pay to") {
                        Text(text, style = type.body, color = c.text, modifier = Modifier.padding(Space.s4))
                        RowDivider(inset = false)
                        CopyRow("All the details", "Copy them in one go", text)
                    }
                }
            } else {
                payment?.accounts?.forEach { a ->
                    SettingsSection("Pay to") {
                        a.title?.let { CopyRow("Account title", it) }
                        a.iban?.let {
                            RowDivider(inset = false)
                            CopyRow("IBAN", PlanFormat.groupIban(it), it.replace(" ", ""), mono = true)
                        }
                        a.accountNumber?.let {
                            RowDivider(inset = false)
                            CopyRow("Account number", it, it.filter(Char::isDigit), mono = true)
                        }
                        a.bank?.let {
                            RowDivider(inset = false)
                            CopyRow("Bank", listOfNotNull(it, a.branch).joinToString(", "), it)
                        }
                    }
                }
            }
            request.referenceCode?.let { ref ->
                SettingsSection(
                    "Reference",
                    footer = "If your bank app has a note or remarks box, write this. If you forget, that's fine. Your receipt is enough."
                ) { CopyRow("Reference", ref, mono = true) }
            }
            Column(
                Modifier.padding(horizontal = Space.screen + Space.s1).padding(bottom = Space.s4),
                verticalArrangement = Arrangement.spacedBy(Space.s3)
            ) {
                Text("Pay from any bank app, JazzCash, Easypaisa, NayaPay or SadaPay.", style = type.body, color = c.text)
                account?.title?.let {
                    Text(
                        "Your bank app shows the account title before you confirm. It should say $it. If it doesn't, stop and contact us.",
                        style = type.body,
                        color = c.text
                    )
                }
                if (!payment?.accounts.isNullOrEmpty()) {
                    payment?.note?.takeIf { it.isNotBlank() }?.let { Text(it, style = type.body, color = c.textMuted) }
                }
                EchoButton("Share details", {
                    val text = buildString {
                        appendLine("Pay for EchoLedger $planName")
                        (amount?.let { PlanFormat.rupees(it) } ?: request.amountDue)?.let { appendLine("Amount: $it") }
                        account?.title?.let { appendLine("Account title: $it") }
                        account?.iban?.let { appendLine("IBAN: ${it.replace(" ", "")}") }
                        account?.accountNumber?.let { appendLine("Account number: $it") }
                        account?.bank?.let { appendLine("Bank: $it") }
                        request.referenceCode?.let { appendLine("Reference: $it") }
                        if (account == null) (payment?.note ?: request.paymentInstructions)?.let { appendLine(it) }
                    }.trim()
                    runCatching {
                        context.startActivity(
                            Intent.createChooser(
                                Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text),
                                "Share payment details"
                            )
                                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        )
                    }
                }, kind = ButtonKind.Text)
            }
        }
        BottomBar(
            caption = "Paid? Add a screenshot of the receipt so we can check it.",
            left = null,
            error = null,
            button = { EchoButton("Add payment receipt", onReceipt, Modifier.fillMaxWidth()) },
            extra = { EchoButton("I'll pay later", onLater, Modifier.fillMaxWidth(), kind = ButtonKind.Text) }
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ReceiptStep(vm: MainViewModel, request: UpgradeRequestDto, onSent: () -> Unit) {
    val scope = rememberCoroutineScope()
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
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
                    vm.uploadReceipt(request.id, uri).error?.let { error = it }
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
            Modifier.weight(
                1f
            ).verticalScroll(rememberScrollState()).padding(horizontal = Space.screen).padding(top = Space.s2, bottom = Space.s4),
            verticalArrangement = Arrangement.spacedBy(Space.s4)
        ) {
            request.payment?.proofRequest?.note?.takeIf { request.stage == "needs_receipt" && it.isNotBlank() }?.let {
                InlineBanner("We need another receipt: $it", kind = BannerKind.Warning, onDismiss = null)
            }
            Text(
                "A screenshot or PDF of your receipt. It should show the amount, the date and time, and the transaction ID. You can cover your balance.",
                style = type.body,
                color = c.text
            )
            if (sent.isNotEmpty() || round.isNotEmpty() || uploading > 0) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s3), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
                    sent.forEach { ReceiptTile(vm, request.id, it, onRemove = null) }
                    round.forEachIndexed { i, p ->
                        ReceiptTile(vm, request.id, p, onRemove = {
                            scope.launch { vm.removeReceipt(request.id, p.id)?.let { error = it } }
                        }, index = i + 1)
                    }
                    repeat(uploading) { UploadingTile() }
                }
            }
            if (room > 0) {
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s2), verticalAlignment = Alignment.CenterVertically) {
                    EchoButton("Add screenshot", {
                        val media = PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)
                        if (room >= 2) pickMany.launch(media) else pickOne.launch(media)
                    }, kind = ButtonKind.Secondary)
                    EchoButton("Use a PDF instead", { pickPdf.launch(arrayOf("application/pdf")) }, kind = ButtonKind.Text)
                }
            } else {
                Text(
                    "You can add up to 3 files at a time. Remove one to add another.",
                    style = type.labelM.copy(fontWeight = FontWeight.Normal),
                    color = c.textMuted
                )
            }
            OutlinedTextField(
                reference,
                { reference = it.filter { ch -> ch.isLetterOrDigit() || ch in " ._/#-" }.take(64) },
                label = { Text("Transaction ID (optional)") },
                supportingText = { Text("On your receipt as Transaction ID, TID or Reference no.") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters),
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                payer,
                { payer = it.take(120) },
                label = { Text("Paid from (optional)") },
                supportingText = { Text("The name on the account you paid from, if it isn't yours.") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            Text(
                "Only our payments team sees your receipt. We delete it 90 days after we decide.",
                style = type.labelM.copy(fontWeight = FontWeight.Normal),
                color = c.textMuted
            )
        }
        BottomBar(
            caption = null,
            left = null,
            error = error,
            button = {
                EchoButton("Send receipt", {
                    sending = true
                    error = null
                    scope.launch {
                        val result = vm.sendReceipt(request.id, reference.ifBlank { null }, payer.ifBlank { null })
                        sending = false
                        if (result.value != null) onSent() else error = result.error
                    }
                }, Modifier.fillMaxWidth(), enabled = uploading == 0 && (round.isNotEmpty() || refOk), loading = sending)
            }
        )
    }
}

@Composable
private fun ReceiptTile(vm: MainViewModel, requestId: String, proof: ProofDto, onRemove: (() -> Unit)?, index: Int = 0) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    var full by remember { mutableStateOf(false) }
    val bitmap by produceState<ImageBitmap?>(null, proof.id) {
        if (proof.kind == "image") {
            value =
                vm.receiptImage(requestId, proof.id)?.let { bytes -> BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap() }
        }
    }
    Column(Modifier.size(width = 96.dp, height = if (onRemove == null) 120.dp else 96.dp)) {
        Box(
            Modifier.size(96.dp).clip(RoundedCornerShape(12.dp)).background(c.surface2).border(1.dp, c.border, RoundedCornerShape(12.dp))
                .clickable(enabled = proof.kind == "image", onClickLabel = "Open receipt") { full = true }
        ) {
            val img = bitmap
            if (img != null) {
                Image(img, contentDescription = "Receipt", contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            } else if (proof.kind != "pdf") {
                Icon(
                    Icons.Outlined.Image,
                    contentDescription = "Receipt",
                    tint = c.textMuted,
                    modifier = Modifier.size(28.dp).align(Alignment.Center)
                )
            } else {
                Column(
                    Modifier.fillMaxSize().padding(Space.s2),
                    verticalArrangement = Arrangement.Center,
                    horizontalAlignment = Alignment.CenterHorizontally
                ) {
                    Icon(Icons.Outlined.PictureAsPdf, contentDescription = null, tint = c.textMuted, modifier = Modifier.size(24.dp))
                    Text(proof.name ?: "Receipt", style = type.caption, color = c.textMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(PlanFormat.fileSize(proof.bytes), style = type.caption, color = c.textMuted)
                }
            }
            if (onRemove != null) {
                IconButton(onClick = onRemove, modifier = Modifier.align(Alignment.TopEnd).size(40.dp)) {
                    Box(Modifier.size(24.dp).clip(CircleShape).background(c.bg.copy(alpha = 0.85f)), contentAlignment = Alignment.Center) {
                        Icon(
                            Icons.Rounded.Close,
                            contentDescription = "Remove receipt $index",
                            tint = c.text,
                            modifier = Modifier.size(16.dp)
                        )
                    }
                }
            }
        }
        if (onRemove == null) {
            Text(
                PlanFormat.shortDate(proof.createdAt)?.let {
                    "Sent $it"
                } ?: "Sent",
                style = type.caption,
                color = c.textMuted,
                modifier = Modifier.padding(top = 4.dp)
            )
        }
    }
    if (full) {
        val big by produceState<ImageBitmap?>(null, proof.id) {
            value =
                vm.receiptImage(requestId, proof.id, "full")?.let { bytes ->
                    BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap()
                }
        }
        Dialog(onDismissRequest = { full = false }, properties = DialogProperties(usePlatformDefaultWidth = false)) {
            Box(
                Modifier.fillMaxSize().background(androidx.compose.ui.graphics.Color.Black).clickable {
                    full = false
                },
                contentAlignment = Alignment.Center
            ) {
                (big ?: bitmap)?.let {
                    Image(it, contentDescription = "Receipt", contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())
                }
                IconButton(onClick = { full = false }, modifier = Modifier.align(Alignment.TopEnd).statusBarsPadding()) {
                    Icon(Icons.Rounded.Close, contentDescription = "Close", tint = androidx.compose.ui.graphics.Color.White)
                }
            }
        }
    }
}

@Composable
private fun UploadingTile() {
    Box(
        Modifier.size(96.dp).clip(RoundedCornerShape(12.dp)).background(LocalEchoColors.current.surface2).semantics {
            contentDescription =
                "Uploading receipt"
        },
        contentAlignment = Alignment.Center
    ) { com.echoledger.mobile.ui.design.Spinner(20.dp, Brand.Muted) }
}

@Composable
private fun StatusStep(vm: MainViewModel, plan: PlanState, request: UpgradeRequestDto, planName: String, go: (FlowStep) -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val is24 = android.text.format.DateFormat.is24HourFormat(context)
    var confirmCancel by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val copy = RequestCopy.card(request, planName, is24)
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(top = Space.s2, bottom = Space.s5)) {
        SettingsSection(null) {
            Column(Modifier.padding(Space.s4), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        copy.title,
                        style = type.bodyL.copy(fontWeight = FontWeight.SemiBold),
                        color = c.text,
                        modifier = Modifier.weight(1f)
                    )
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
            }
        }
        SettingsSection(null) { Timeline(request, planName, is24) }
        SettingsSection("Details") {
            val rows = buildList {
                add("Plan" to planName)
                (request.amountDuePkr?.let { PlanFormat.rupees(it) } ?: request.amountDue)?.let { add("Amount" to it) }
                request.promoCode?.let { add("Promo code" to it) }
                PlanFormat.dateWithYear(request.createdAt)?.let { add("Requested" to it) }
                request.payment?.reference?.let { add("Transaction ID" to it) }
            }
            rows.forEachIndexed { i, (k, v) ->
                if (i > 0) RowDivider(inset = false)
                com.echoledger.mobile.ui.design.SettingsRow(null, k, value = v)
            }
            request.referenceCode?.let {
                RowDivider(inset = false)
                CopyRow("Reference", it, mono = true)
            }
        }
        val proofs = request.payment?.proofs.orEmpty()
        if (proofs.isNotEmpty()) {
            Text(
                "Receipts",
                style = type.labelM.copy(fontWeight = FontWeight.SemiBold),
                color = c.textMuted,
                modifier = Modifier.padding(
                    start =
                        Space.screen + Space.s1,
                    bottom = Space.s2
                )
            )
            Row(
                Modifier.padding(horizontal = Space.screen).padding(bottom = Space.s4),
                horizontalArrangement = Arrangement.spacedBy(Space.s3)
            ) {
                proofs.forEach { ReceiptTile(vm, request.id, it, onRemove = null) }
            }
        }
        error?.let { InlineBanner(it, Modifier.padding(horizontal = Space.screen), kind = BannerKind.Error, onDismiss = { error = null }) }
        Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
            if (request.stage == "awaiting_payment" || request.stage == "needs_receipt") {
                EchoButton("Add receipt", { go(FlowStep.Receipt) }, Modifier.fillMaxWidth())
            }
            if (request.open) {
                EchoButton("Payment details", { go(FlowStep.Pay) }, Modifier.fillMaxWidth(), kind = ButtonKind.Secondary)
                EchoButton("Cancel request", { confirmCancel = true }, kind = ButtonKind.Text)
            }
            if (request.stage == "declined" || request.stage == "cancelled") {
                EchoButton("Request again", { go(FlowStep.Choose) }, Modifier.fillMaxWidth(), kind = ButtonKind.Secondary)
            }
        }
    }
    if (confirmCancel) {
        CancelRequestDialog(onKeep = { confirmCancel = false }) {
            confirmCancel = false
            scope.launch { vm.cancelUpgradeRequest(request.id).error?.let { error = it } }
        }
    }
}

/** Requested → Receipt sent → Checked by our team → {Plan} on; a closed request ends in one final step. */
@Composable
private fun Timeline(request: UpgradeRequestDto, planName: String, is24: Boolean) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    fun at(step: String) = request.timeline.lastOrNull { it.step == step }?.at
    fun stamp(iso: String?) = listOfNotNull(PlanFormat.shortDate(iso), PlanFormat.time(iso, is24)).joinToString(", ").ifEmpty { null }
    val sentAt = at("receipt_sent") ?: request.payment?.paidAt
    val decidedAt = at("decided") ?: request.decidedAt
    data class Step(val label: String, val done: Boolean, val current: Boolean, val time: String?, val note: String? = null)
    val steps = buildList {
        add(Step("Requested", true, false, stamp(at("requested") ?: request.createdAt)))
        val asked = request.stage == "needs_receipt"
        add(Step("Receipt sent", sentAt != null && !asked, request.stage == "awaiting_payment" || asked, stamp(sentAt)))
        if (asked) {
            add(
                Step("New receipt asked for", true, false, stamp(request.payment?.proofRequest?.at), request.payment?.proofRequest?.note)
            )
        }
        when (request.stage) {
            "declined" -> add(Step("Not approved", true, false, stamp(decidedAt)))

            "cancelled" -> add(Step("Cancelled by you", true, false, stamp(at("cancelled") ?: decidedAt)))

            else -> {
                val approved = request.stage == "approved"
                add(Step("Checked by our team", approved, request.stage == "in_review", stamp(decidedAt)))
                add(Step("$planName on", approved, false, PlanFormat.dateWithYear(request.approvedUntil)?.let { "until $it" }))
            }
        }
    }
    Column(Modifier.padding(Space.s4)) {
        steps.forEachIndexed { i, s ->
            Row(Modifier.semantics(mergeDescendants = true) {}) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(
                        Modifier.size(20.dp).clip(CircleShape).then(
                            when {
                                s.done -> Modifier.background(c.text)
                                s.current -> Modifier.border(2.dp, c.accent, CircleShape)
                                else -> Modifier.border(1.5.dp, c.borderStrong, CircleShape)
                            }
                        ),
                        contentAlignment = Alignment.Center
                    ) {
                        when {
                            s.done -> Icon(
                                Icons.Rounded.Check,
                                contentDescription = null,
                                tint = c.surface,
                                modifier = Modifier.size(14.dp)
                            )

                            s.current -> Box(Modifier.size(8.dp).clip(CircleShape).background(c.accent))
                        }
                    }
                    if (i < steps.lastIndex) Box(Modifier.size(width = 2.dp, height = 28.dp).background(c.border))
                }
                Spacer(Modifier.size(Space.s3))
                Column(Modifier.padding(bottom = if (i < steps.lastIndex) Space.s2 else 0.dp)) {
                    Text(
                        s.label,
                        style = type.body,
                        color = if (s.done || s.current) c.text else c.textMuted,
                        modifier = Modifier.semantics {
                            contentDescription =
                                "${s.label}, ${if (s.done) {
                                    "done"
                                } else if (s.current) {
                                    "current"
                                } else {
                                    "not yet"
                                }}${s.time?.let { ", $it" }.orEmpty()}"
                        }
                    )
                    s.time?.takeIf {
                        s.done || s.current
                    }?.let { Text(it, style = type.labelM.copy(fontWeight = FontWeight.Normal), color = c.textMuted) }
                    s.note?.let { Text(it, style = type.labelM.copy(fontWeight = FontWeight.Normal), color = c.textMuted) }
                }
            }
        }
    }
}

@Composable
private fun ApprovedStep(plan: PlanState, request: UpgradeRequestDto?, onDone: () -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val name = plan.nameOf(request?.approvedPlan ?: request?.plan)
    Column(
        Modifier.fillMaxSize().padding(Space.screen),
        verticalArrangement = Arrangement.spacedBy(Space.s3),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Spacer(Modifier.size(Space.s6))
        SuccessCheck(c.success, size = 48.dp)
        Text("You're on $name", style = type.titleM, color = c.text)
        Text(
            "Thank you." + (PlanFormat.longDate(request?.approvedUntil)?.let { " $name is on until $it." } ?: ""),
            style = type.body,
            color = c.textMuted
        )
        Spacer(Modifier.size(Space.s4))
        EchoButton("Done", onDone, Modifier.fillMaxWidth())
    }
}
