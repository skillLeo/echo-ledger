package com.echoledger.mobile.ui.v2.settings

import android.content.Intent
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.semantics
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.R
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LimitDto
import com.echoledger.mobile.data.StorageDto
import com.echoledger.mobile.ui.LocalUnlockPrompter
import com.echoledger.mobile.ui.LockAfter
import com.echoledger.mobile.ui.Method
import com.echoledger.mobile.ui.REASON_NO_SCREEN_LOCK
import com.echoledger.mobile.ui.REASON_SECURITY_UPDATE
import com.echoledger.mobile.ui.lockAvailability
import com.echoledger.mobile.ui.rememberUnlockPrompter
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSkeletonBlock
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.daysWords
import com.echoledger.mobile.ui.v2.library.keepWords
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

@Composable
private fun Page(title: String, onBack: () -> Unit, modifier: Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(title, onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), content = content)
    }
}

@Composable
private fun Note(words: String, modifier: Modifier = Modifier) {
    Text(
        words,
        style = EchoV2.type.meta,
        color = EchoV2.colors.textSecondary,
        modifier = modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
}

/**
 * App lock (Privacy & security, IA 5.4). The lock is the phone's own screen lock (fingerprint, face, PIN, pattern or
 * password; the app keeps no PIN of its own): without one, why not and Open security settings, where the PIN is set or
 * changed (checked again on coming back). Turning the lock on asks for an unlock first and stays off if it's refused;
 * when on, Lock after and Hide content; when off, Hide recording titles on the widget. Reset to defaults asks first.
 * Every control and state of today's page (PrivacyPagesParityTest).
 */
@Composable
fun AppLockPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    setWidgetHideTitles: (Boolean) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    var recheck by remember { mutableIntStateOf(0) }
    // A screen lock set (or removed) in the phone's settings counts as soon as the person comes back.
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { recheck++ }
    val availability = remember(recheck) { lockAvailability(context) }
    val prompter = LocalUnlockPrompter.current ?: rememberUnlockPrompter()
    val available = availability.method != Method.None
    val on = settings.appLockEnabled && available
    var prompting by remember { mutableStateOf(false) }
    val title = stringResource(R.string.v2_set_page_lock)
    Page(title, onBack, modifier) {
        EchoSwitch(
            checked = on,
            onCheckedChange = { wanted ->
                if (!wanted) {
                    update { it.copy(appLockEnabled = false) }
                } else if (prompter != null && !prompting) {
                    prompting = true
                    prompter.prompt { ok ->
                        prompting = false
                        if (ok) update { it.copy(appLockEnabled = true) }
                    }
                }
            },
            label = stringResource(R.string.v2_priv_lock_title),
            supporting = stringResource(R.string.v2_priv_lock_line),
            enabled = available && prompter != null,
            modifier = Modifier.highlightedIf(highlight == "lock.app")
        )
        if (!available) {
            // A reason this page doesn't know yet reads as the common one: set a screen lock.
            val reason = LOCK_REASONS[availability.reason] ?: R.string.v2_priv_lock_no_screen_lock
            EchoBanner(
                stringResource(reason),
                EchoBannerKind.Warning,
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                actionLabel = stringResource(R.string.v2_priv_lock_open_security),
                onAction = { runCatching { context.startActivity(Intent(Settings.ACTION_SECURITY_SETTINGS)) } }
            )
        }
        if (on) {
            EchoSectionHeader(stringResource(R.string.v2_priv_lock_after))
            val lockAfter = LockAfter.normalize(settings.lockAfterMs)
            val words = mapOf(
                LockAfter.IMMEDIATELY to R.string.v2_priv_lock_now,
                LockAfter.ONE_MINUTE to R.string.v2_priv_lock_1min,
                LockAfter.FIVE_MINUTES to R.string.v2_priv_lock_5min
            )
            Column(Modifier.padding(horizontal = EchoTokens.Space.s12)) {
                LockAfter.choices.forEach { (ms, _) ->
                    EchoRadio(lockAfter == ms, { update { it.copy(lockAfterMs = ms) } }, stringResource(words.getValue(ms)))
                }
            }
            // Hiding the app's content in screenshots and Recents is Privacy & data's Block screenshots now (2.0.5).
        } else {
            // With the lock on the widget shows no recordings, so its titles matter only while it's off.
            EchoSwitch(
                checked = settings.widgetHideTitles,
                onCheckedChange = setWidgetHideTitles,
                label = stringResource(R.string.v2_priv_lock_widget),
                supporting = stringResource(R.string.v2_priv_lock_widget_line),
                modifier = Modifier.highlightedIf(highlight == "lock.hide")
            )
        }
        PageReset("lock", title, update)
    }
}

/** Android 15: the first with the screen-recording callback that hides the floating windows during a share. */
const val SCREEN_RECORDING_CALLBACK_SDK = 35

/** Why App lock can't be used, in the screen's language: each reason of [lockAvailability] by its own words. */
internal val LOCK_REASONS: Map<String, Int> = mapOf(
    REASON_NO_SCREEN_LOCK to R.string.v2_priv_lock_no_screen_lock,
    REASON_SECURITY_UPDATE to R.string.v2_priv_lock_security_update
)

/**
 * Privacy & data: recording consent (I understand), Block screenshots of EchoLedger (off by default: the app's own
 * screens and every floating window; the line for Android 10 to 14 below it), the floating windows during a screen
 * share (Private, a black box in screenshots and shares on every Android version, or Show), and Export or delete my
 * data (on Sign-in & security, [onOpenPage]).
 */
@Composable
fun PrivacyPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    highlight: String?,
    onOpenPage: (String) -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    /** The phone's Android version: below 15 there is no screen-recording callback (the help line says so). */
    sdkInt: Int = android.os.Build.VERSION.SDK_INT
) {
    Page(stringResource(R.string.v2_set_page_privacy), onBack, modifier) {
        Column(Modifier.highlightedIf(highlight == "privacy.consent")) {
            EchoSectionHeader(stringResource(R.string.v2_set_item_privacy_consent))
            Note(stringResource(R.string.v2_priv_consent_line))
            EchoSwitch(
                checked = settings.consentAcknowledged,
                onCheckedChange = { v -> update { it.copy(consentAcknowledged = v) } },
                label = stringResource(R.string.v2_priv_consent_ok)
            )
        }
        Column(Modifier.highlightedIf(highlight == "privacy.block")) {
            EchoSwitch(
                checked = settings.blockScreens,
                onCheckedChange = { v -> update { it.copy(blockScreens = v) } },
                label = stringResource(R.string.cc_block_screens),
                supporting = stringResource(R.string.cc_block_screens_help)
            )
            if (sdkInt < SCREEN_RECORDING_CALLBACK_SDK) Note(stringResource(R.string.cc_block_screens_help_old_android))
        }
        Column(Modifier.highlightedIf(highlight == "privacy.share")) {
            EchoSectionHeader(stringResource(R.string.v2_set_item_privacy_share))
            Note(stringResource(R.string.v2_priv_share_line))
            val current = ShareGuard.Mode.of(settings.screenShareMode)
            Column(Modifier.padding(horizontal = EchoTokens.Space.s12)) {
                listOf(
                    Triple(ShareGuard.Mode.PRIVATE, R.string.v2_priv_share_private, R.string.v2_priv_share_private_line),
                    Triple(ShareGuard.Mode.SHOW, R.string.v2_priv_share_show, R.string.v2_priv_share_show_line)
                ).forEach { (mode, label, line) ->
                    EchoRadio(
                        current == mode,
                        { update { it.copy(screenShareMode = mode.wire) } },
                        stringResource(label),
                        supporting = stringResource(line)
                    )
                }
            }
            Note(stringResource(R.string.v2_priv_share_note))
        }
        EchoButton(
            stringResource(R.string.v2_priv_export),
            { onOpenPage("account") },
            Modifier.padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8),
            kind = EchoButtonKind.Quiet
        )
    }
}

/**
 * Storage: this person's recordings on the server ([load], `GET /api/storage`; never the server's own disk), the
 * plan's storage with its meter, how long recordings are kept (the server's retention days) and how long Trash can
 * restore them ([keepHours], the server's `trash.keep_hours`: "48 hours"; 720 in whole days).
 */
@Composable
fun StoragePage(
    load: suspend () -> StorageDto?,
    planStorage: LimitDto?,
    keepHours: Int,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    var info by remember { mutableStateOf<StorageDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(attempt) {
        info = load()
        loaded = true
    }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
    Page(stringResource(R.string.v2_set_page_storage), onBack, modifier) {
        val current = info
        when {
            !loaded -> Column(pad, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoSkeletonBlock(fraction = 0.6f)
                EchoSkeletonBlock(fraction = 0.9f)
            }

            current == null -> EchoBanner(
                stringResource(R.string.v2_priv_storage_error),
                EchoBannerKind.Warning,
                pad,
                actionLabel = stringResource(R.string.v2_priv_storage_retry),
                onAction = {
                    loaded = false
                    attempt++
                }
            )

            else -> {
                Column(Modifier.highlightedIf(highlight == "storage.space")) {
                    EchoSectionHeader(stringResource(R.string.v2_set_item_storage_space))
                    val size = LibraryModel.size(current.recordingsBytes)
                    Text(
                        if (current.recordingsCount == 1) {
                            stringResource(R.string.v2_priv_storage_one, size)
                        } else {
                            stringResource(R.string.v2_priv_storage_many, size, current.recordingsCount)
                        },
                        style = EchoV2.type.body,
                        color = c.textPrimary,
                        modifier = pad
                    )
                    val limit = planStorage?.limit
                    if (planStorage != null && limit != null) {
                        val used = planStorage.used
                        Note(
                            stringResource(R.string.v2_priv_storage_plan) + ": " + if (used == null) {
                                stringResource(R.string.v2_priv_storage_plan_limit, LibraryModel.size(limit))
                            } else {
                                stringResource(R.string.v2_priv_storage_plan_of, LibraryModel.size(used), LibraryModel.size(limit))
                            }
                        )
                        val meter = stringResource(R.string.v2_priv_storage_meter)
                        com.echoledger.mobile.core.PlanFormat.fraction(used, limit)?.let { Meter(it, meter) }
                    }
                }
                Column(Modifier.highlightedIf(highlight == "storage.kept")) {
                    EchoSectionHeader(stringResource(R.string.v2_set_item_storage_kept))
                    Note(stringResource(R.string.v2_priv_storage_kept, daysWords(res, current.retentionDays)))
                    Note(stringResource(R.string.v2_priv_storage_restore, keepWords(res, keepHours)))
                }
            }
        }
    }
}

/** A thin meter: the used share of the plan's storage. */
@Composable
private fun Meter(used: Float, label: String) {
    val c = EchoV2.colors
    Box(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
            .fillMaxWidth()
            .height(EchoTokens.Space.s4)
            .clip(RoundedCornerShape(EchoTokens.Radius.full))
            .background(c.bgSubtle)
            .semantics {
                contentDescription = label
                progressBarRangeInfo = ProgressBarRangeInfo(used, 0f..1f)
            }
    ) {
        Box(Modifier.fillMaxWidth(used).fillMaxHeight().background(c.accent))
    }
}
