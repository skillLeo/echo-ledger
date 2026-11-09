package com.echoledger.mobile.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import kotlinx.coroutines.launch

/** A plan's colour from the server ("#7c3aed"), else the accent. */
@Composable
internal fun planColor(hex: String?): Color = runCatching { Color(android.graphics.Color.parseColor(hex)) }.getOrDefault(Brand.AccentText)

/**
 * The one badge for a feature the plan lacks (since v1.6, P1.4): "PRO" plus the plan mark in the plan's colour with a small lock; a tap
 * opens the upgrade sheet.
 */
@Composable
fun PlanBadge(lock: PlanState.Lock, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val tint = planColor(lock.color)
    Row(
        modifier
            .clip(CircleShape)
            .background(tint.copy(alpha = 0.14f))
            .clickable(role = Role.Button, onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 3.dp)
            .semantics {
                contentDescription =
                    "${lock.featureName}: in ${lock.badge.removeSuffix(" " + PlanState.PLAN_MARK)}. Tap to see the plans."
            },
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(Icons.Rounded.Lock, contentDescription = null, tint = tint, modifier = Modifier.size(12.dp))
        Spacer(Modifier.width(4.dp))
        Text(lock.badge, color = tint, fontSize = 11.sp, fontWeight = FontWeight.Bold)
    }
}

/**
 * What a locked feature does, the plans side by side, and "Request upgrade" (an optional message). After the request:
 * the owner's payment instructions and "We'll activate it once confirmed". A pending request says so.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun UpgradeSheet(vm: MainViewModel, plan: PlanState, lock: PlanState.Lock?, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var message by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    var sent by remember { mutableStateOf<UpgradeRequestDto?>(null) }
    val pending = sent ?: plan.upgrade?.takeIf { it.status == "pending" }
    val target = lock?.planKey?.takeIf { it.isNotBlank() } ?: plan.offers.firstOrNull { it.key != plan.key }?.key.orEmpty()
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Brand.Surface) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = Space.screen).padding(bottom = Space.s5),
            verticalArrangement = Arrangement.spacedBy(Space.s3)
        ) {
            if (lock != null) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(lock.featureName, style = LocalEchoType.current.titleM, color = Brand.Text, modifier = Modifier.weight(1f))
                    PlanBadge(lock, onClick = {})
                }
                if (lock.description.isNotBlank()) Text(lock.description, color = Brand.Muted)
            } else {
                Text("Plans", style = LocalEchoType.current.titleM, color = Brand.Text)
            }
            plan.offers.forEach { offer ->
                val current = offer.key == plan.key
                Column(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp))
                        .background(if (offer.key == target) planColor(offer.color).copy(alpha = 0.08f) else Brand.Background)
                        .padding(Space.s3)
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(offer.name, fontWeight = FontWeight.SemiBold, color = Brand.Text, modifier = Modifier.weight(1f))
                        Text(if (current) "Your plan" else offer.price.orEmpty(), color = Brand.Muted, fontSize = 13.sp)
                    }
                    offer.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = Brand.Muted, fontSize = 13.sp) }
                    val names = offer.features.mapNotNull { plan.features[it]?.name?.takeIf { n -> n.isNotBlank() } }
                    if (names.isNotEmpty()) Text(names.joinToString(" · "), color = Brand.Text, fontSize = 13.sp)
                }
            }
            when {
                pending != null -> {
                    Text("Upgrade requested", fontWeight = FontWeight.SemiBold, color = Brand.Text)
                    pending.paymentInstructions?.takeIf { it.isNotBlank() }?.let { Text(it, color = Brand.Text) }
                    Text("We'll activate it once confirmed.", color = Brand.Muted)
                }

                target.isNotBlank() -> {
                    OutlinedTextField(
                        message,
                        { message = it.take(1000) },
                        label = { Text("Message (optional)") },
                        modifier = Modifier.fillMaxWidth()
                    )
                    EchoButton(
                        "Request upgrade",
                        {
                            sending = true
                            scope.launch {
                                sent = vm.requestUpgrade(target, message)
                                sending = false
                            }
                        },
                        Modifier.fillMaxWidth(),
                        loading = sending
                    )
                }
            }
        }
    }
}

/** You → Plan & usage (since v1.6): the plan, how much of each limit is used, a pending request, and the plans. */
@Composable
fun PlanSection(vm: MainViewModel, plan: PlanState) {
    var sheet by remember { mutableStateOf(false) }
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(plan.name ?: "Your plan", fontWeight = FontWeight.SemiBold, color = Brand.Text, modifier = Modifier.weight(1f))
            plan.badge?.let { Text(PlanState.badgeText(it), color = planColor(plan.color), fontWeight = FontWeight.Bold, fontSize = 12.sp) }
        }
        plan.expiresAt?.let { Text("Until ${it.take(10)}, then the Free plan.", color = Brand.Muted, fontSize = 13.sp) }
        LIMIT_NAMES.forEach { (key, label) ->
            plan.limits[key]?.let { l -> Text("$label: ${limitText(key, l.used, l.limit)}", color = Brand.Text, fontSize = 14.sp) }
        }
        plan.upgrade?.takeIf {
            it.status == "pending"
        }?.let { Text("Upgrade to ${it.plan} requested. We'll activate it once confirmed.", color = Brand.Muted) }
        plan.upgrade?.takeIf {
            it.status == "declined"
        }?.let { Text("Your upgrade request was declined${it.reply?.let { r -> ": $r" }.orEmpty()}", color = Brand.Muted) }
        if (plan.offers.isNotEmpty()) EchoButton("See plans", { sheet = true }, kind = ButtonKind.Secondary)
    }
    if (sheet) UpgradeSheet(vm, plan, null) { sheet = false }
}

private val LIMIT_NAMES = listOf(
    "monthly_minutes" to "Transcription this month",
    "devices" to "Devices",
    "storage_bytes" to "Storage",
    "retention_days" to "Recordings kept",
    "seats" to "People"
)

/** "120 of 600 min", "1 of 2", "50 MB of 1 GB", "30 days", "No limit". Pure. */
fun limitText(key: String, used: Long?, limit: Long?): String {
    fun size(b: Long) = if (b >= 1L shl 30) "${(b shr 30)} GB" else "${(b shr 20)} MB"
    fun value(v: Long) = when (key) {
        "storage_bytes" -> size(v)
        "monthly_minutes" -> "$v min"
        "retention_days" -> "$v days"
        else -> "$v"
    }
    return when {
        limit == null && used == null -> "No limit"
        limit == null -> "${value(used ?: 0)} · no limit"
        key == "retention_days" -> value(limit)
        else -> "${value(used ?: 0)} of ${value(limit)}"
    }
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
