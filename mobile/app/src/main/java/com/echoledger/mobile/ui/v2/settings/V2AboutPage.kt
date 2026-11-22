package com.echoledger.mobile.ui.v2.settings

import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import com.echoledger.mobile.update.AppUpdates
import com.echoledger.mobile.update.SelfUpdate
import com.echoledger.mobile.update.UpdateStatus
import java.time.LocalDate

/** What the update line says and its one button, by where the update stands (classic's UpdateText, in the screen's language). */
data class UpdateWords(val text: String?, val label: String?, val warn: Boolean = false)

@Composable
fun updateWords(status: UpdateStatus): UpdateWords = when (status) {
    UpdateStatus.Idle -> UpdateWords(null, stringResource(R.string.v2_ab_check))

    UpdateStatus.Checking -> UpdateWords(stringResource(R.string.v2_ab_checking), null)

    UpdateStatus.UpToDate -> UpdateWords(stringResource(R.string.v2_ab_newest), stringResource(R.string.v2_ab_check))

    is UpdateStatus.Available -> UpdateWords(
        stringResource(R.string.v2_ab_ready, status.release.version, AppUpdates.size(status.release.bytes)),
        stringResource(R.string.v2_ab_update)
    )

    is UpdateStatus.Downloading -> UpdateWords(stringResource(R.string.v2_ab_downloading, status.percent), null)

    is UpdateStatus.NeedsPermission -> UpdateWords(
        stringResource(R.string.v2_ab_allow_help),
        stringResource(R.string.v2_ab_allow),
        warn = true
    )

    is UpdateStatus.Installing -> UpdateWords(stringResource(R.string.v2_ab_installing), null)

    is UpdateStatus.Failed -> UpdateWords(
        status.message,
        stringResource(if (status.release != null) R.string.v2_ab_try_again else R.string.v2_ab_check),
        warn = true
    )
}

/** The calls About makes (the view model's, or a test's). */
class AboutActions(
    val check: () -> Unit,
    val install: (com.echoledger.mobile.data.AndroidReleaseDto) -> Unit,
    val exportTo: (Uri) -> Unit,
    val importFrom: (Uri) -> Unit
)

/**
 * About on the v2 components (classic's About and This phone, the same actions): the version and the server, Check for
 * updates (download, install, Allow installing) in the website build, Export settings and Import settings through the
 * system's file picker (never the pairing, token or lock), this phone's name and the Setup guide.
 */
@Composable
fun AboutPage(
    settings: AppSettings,
    status: UpdateStatus,
    actions: AboutActions,
    highlight: String?,
    onSetup: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    updates: Boolean = SelfUpdate.SUPPORTED
) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val export = rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("application/json")) { uri ->
        if (uri != null) actions.exportTo(uri)
    }
    val import = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri !=
            null
        ) {
            actions.importFrom(uri)
        }
    }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        com.echoledger.mobile.ui.v2.components.EchoTopBar(stringResource(R.string.v2_set_page_about), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(Modifier.highlightedIf(highlight == "about.version")) {
                EchoSectionHeader(stringResource(R.string.v2_set_page_about))
                Text(
                    stringResource(R.string.v2_ab_version, BuildConfig.VERSION_NAME),
                    style = EchoV2.type.body,
                    color = c.textPrimary,
                    modifier = pad
                )
                settings.serverUrl?.let { url ->
                    val host = url.removePrefix("https://").removePrefix("http://")
                    Text(
                        settings.serverApiVersion?.let { stringResource(R.string.v2_ab_server_api, host, it) }
                            ?: stringResource(R.string.v2_ab_server, host),
                        style = EchoV2.type.metaLatin,
                        color = c.textSecondary,
                        modifier = pad
                    )
                }
                if (updates) {
                    val words = updateWords(status)
                    words.text?.let {
                        Text(it, style = EchoV2.type.meta, color = if (words.warn) c.warning else c.textSecondary, modifier = pad)
                    }
                    (status as? UpdateStatus.Available)?.release?.notes?.takeIf { it.isNotBlank() }?.let {
                        Text(it.take(NOTES_MAX), style = EchoV2.type.content(it, EchoV2.type.meta), color = c.textSecondary, modifier = pad)
                    }
                    words.label?.let { label ->
                        EchoButton(label, {
                            when (status) {
                                is UpdateStatus.NeedsPermission -> runCatching {
                                    context.startActivity(
                                        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))
                                            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                    )
                                }

                                is UpdateStatus.Available -> actions.install(status.release)

                                is UpdateStatus.Failed -> status.release?.let(actions.install) ?: actions.check()

                                else -> actions.check()
                            }
                        }, pad, kind = EchoButtonKind.Secondary)
                    }
                }
            }
            Column(Modifier.highlightedIf(highlight == "about.transfer")) {
                EchoSectionHeader(stringResource(R.string.v2_ab_transfer))
                Text(stringResource(R.string.v2_ab_transfer_help), style = EchoV2.type.meta, color = c.textSecondary, modifier = pad)
                Row(pad, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    EchoButton(stringResource(R.string.v2_ab_export), {
                        runCatching { export.launch("EchoLedger settings ${LocalDate.now()}.json") }
                    }, kind = EchoButtonKind.Secondary)
                    EchoButton(stringResource(R.string.v2_ab_import), {
                        runCatching { import.launch(arrayOf("application/json", "text/plain")) }
                    }, kind = EchoButtonKind.Secondary)
                }
            }
            Column(Modifier.highlightedIf(highlight == "about.phone")) {
                EchoSectionHeader(stringResource(R.string.v2_ab_phone))
                settings.deviceName?.takeIf { it.isNotBlank() }?.let {
                    Text(it, style = EchoV2.type.content(it, EchoV2.type.body), color = c.textPrimary, modifier = pad)
                }
                EchoButton(stringResource(R.string.v2_ab_setup_guide), onSetup, pad, kind = EchoButtonKind.Secondary)
            }
        }
    }
}

private const val NOTES_MAX = 600
