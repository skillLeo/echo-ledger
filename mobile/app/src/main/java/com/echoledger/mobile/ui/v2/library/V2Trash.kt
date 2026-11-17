package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.echoledger.mobile.data.BinItemDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.launch

/** "19 Nov, 14:05" (Latin digits), when a binned recording is erased. */
fun eraseDay(iso: String?, locale: Locale, zone: ZoneId = ZoneId.systemDefault()): String? = runCatching {
    LibraryModel.latinDigits(OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("d MMM, HH:mm", locale)))
}.getOrNull()

/** The server's Trash length when nothing says otherwise (docs/API.md 2.0.0-b, the privacy policy). */
const val DEFAULT_TRASH_HOURS = 48

/**
 * How many hours Trash keeps a recording: the config's `trash.keep_hours` when the app has it, else what the server's
 * own dates say (erase_at minus deleted_at of a binned recording), else 48.
 */
fun trashKeepHours(configHours: Int?, items: List<BinItemDto>?): Int {
    configHours?.takeIf { it > 0 }?.let { return it }
    items.orEmpty().firstNotNullOfOrNull { bin ->
        runCatching {
            val minutes = java.time.Duration.between(OffsetDateTime.parse(bin.deletedAt), OffsetDateTime.parse(bin.eraseAt)).toMinutes()
            ((minutes + 30) / 60).toInt().takeIf { it > 0 }
        }.getOrNull()
    }?.let { return it }
    return DEFAULT_TRASH_HOURS
}

/**
 * Library › Trash: recordings the server keeps for [configKeepHours] (`trash.keep_hours`; null until the app reads it),
 * each restorable or erasable now (with a confirmation).
 */
@Composable
fun V2TrashScreen(vm: MainViewModel, configKeepHours: Int?, onBack: () -> Unit, modifier: Modifier = Modifier) {
    var items by remember { mutableStateOf<List<BinItemDto>?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(reload) { items = vm.bin() }
    val scope = rememberCoroutineScope()
    V2TrashContent(
        items = items,
        keepHours = trashKeepHours(configKeepHours, items),
        onBack = onBack,
        onRestore = { id -> scope.launch { if (vm.restore(id)) reload++ } },
        onErase = { id -> scope.launch { if (vm.eraseNow(id)) reload++ } },
        modifier = modifier
    )
}

/** Trash without the view model; [items] null while loading. */
@Composable
fun V2TrashContent(
    items: List<BinItemDto>?,
    keepHours: Int,
    onBack: () -> Unit,
    onRestore: (String) -> Unit,
    onErase: (String) -> Unit,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    val text = v2Text
    val locale = screenLocale()
    var actionsFor by remember { mutableStateOf<BinItemDto?>(null) }
    var confirmErase by remember { mutableStateOf<BinItemDto?>(null) }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(text.trash, onBack = onBack)
        when {
            items == null -> EchoSkeletonList(4, Modifier.weight(1f))

            items.isEmpty() -> EchoEmptyState(text.trashEmptyTitle, text.trashKeeps(keepHours), icon = EchoIcons.Delete)

            else -> LazyColumn(Modifier.weight(1f)) {
                item {
                    Text(
                        text.trashKeeps(keepHours),
                        style = EchoV2.type.meta,
                        color = c.textSecondary,
                        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
                    )
                }
                items(items, key = { it.id }) { bin ->
                    EchoListRow(
                        title = bin.title.ifBlank { text.recordings },
                        meta = eraseDay(bin.eraseAt, locale)?.let(text.erasesOn) ?: bin.client?.name,
                        leading = { EchoAvatar(icon = EchoIcons.Delete) },
                        tall = true,
                        onClick = { actionsFor = bin }
                    )
                }
            }
        }
    }
    actionsFor?.let { bin ->
        EchoBottomSheet(onDismiss = { actionsFor = null }, title = bin.title) {
            EchoSettingRow(text.restore, icon = EchoIcons.Restore, onClick = {
                actionsFor = null
                onRestore(bin.id)
            })
            EchoSettingRow(text.eraseNow, icon = EchoIcons.Delete, onClick = {
                actionsFor = null
                confirmErase = bin
            })
        }
    }
    confirmErase?.let { bin ->
        EchoDialog(
            title = text.eraseTitle,
            text = text.eraseBody,
            confirmLabel = text.eraseNow,
            onConfirm = {
                confirmErase = null
                onErase(bin.id)
            },
            dismissLabel = text.cancel,
            onDismiss = { confirmErase = null },
            destructive = true
        )
    }
}
