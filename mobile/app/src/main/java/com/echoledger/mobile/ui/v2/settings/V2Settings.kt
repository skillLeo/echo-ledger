package com.echoledger.mobile.ui.v2.settings

import android.content.res.Resources
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.help.HelpSource
import com.echoledger.mobile.ui.v2.help.V2HelpScreen
import com.echoledger.mobile.ui.v2.shell.ProfileCardSlot
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.shell.profileOf
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** One search result: the setting, its words in the screen's language and where it lives ("Appearance › Theme"). */
data class SettingHit(val item: SettingsIndex.Item, val title: String, val path: String)

/** Settings v2's search over every individual setting. Pure apart from the resources it reads words from. */
object SettingsSearch {
    fun itemTitle(res: Resources, item: SettingsIndex.Item): String = SettingsTree.itemTitles[item.id]?.let(res::getString) ?: item.title

    fun pageTitle(res: Resources, page: String): String = SettingsTree.pageTitles[page]?.let(res::getString) ?: page

    private fun path(res: Resources, item: SettingsIndex.Item): String {
        val goal = SettingsTree.goalOf(item.page)?.let { res.getString(it.title) }.orEmpty()
        val page = pageTitle(res, item.page)
        return if (page == goal) goal else res.getString(R.string.v2_set_path, goal, page)
    }

    /** Every setting v2 shows: the index's (minus the hidden ones) and v2's own. */
    fun items(): List<SettingsIndex.Item> =
        SettingsIndex.items.filter { it.id !in SettingsTree.hiddenItems && SettingsTree.goalOf(it.page) != null } + SettingsTree.extraItems

    /**
     * The settings matching [query]: the index's own ranking (English and Roman Urdu words), then any whose title in
     * the screen's language contains the query (Urdu), then v2's own by their words. Nothing for a blank query.
     */
    fun search(res: Resources, query: String): List<SettingHit> {
        val q = query.trim()
        if (q.isEmpty()) return emptyList()
        val shown = items().associateBy { it.id }
        val ranked = SettingsIndex.search(q).map { it.item.id }.filter { it in shown }
        val localized = shown.values.filter { itemTitle(res, it).contains(q, ignoreCase = true) }.map { it.id }
        val words = q.lowercase().split(' ').filter { it.isNotBlank() }
        val extra = SettingsTree.extraItems.filter { item ->
            words.all { w -> "${item.title} ${item.keywords}".lowercase().contains(w) }
        }.map { it.id }
        return (ranked + localized + extra).distinct().map { id ->
            shown.getValue(id).let { SettingHit(it, itemTitle(res, it), path(res, it)) }
        }
    }
}

/** A page's value on its root row, in the screen's language; null for pages that show none. */
fun pageValue(res: Resources, page: String, s: AppSettings): String? = when (page) {
    "appearance" -> res.getString(
        when (s.themeMode) {
            "light" -> R.string.v2_set_value_light
            "dark" -> R.string.v2_set_value_dark
            else -> R.string.v2_set_value_system
        }
    )

    "notifications" -> when {
        !s.activityNotifications -> res.getString(R.string.v2_set_value_off)
        s.quietHours -> res.getString(R.string.v2_set_value_quiet, s.quietFrom, s.quietTo)
        else -> res.getString(R.string.v2_set_value_on)
    }

    "lock" -> res.getString(if (s.appLockEnabled) R.string.v2_set_value_on else R.string.v2_set_value_off)

    "captions" -> res.getString(if (s.captionsEnabled) R.string.v2_set_value_on else R.string.v2_set_value_off)

    "language" -> if (s.transcriptionLanguage ==
        null
    ) {
        res.getString(R.string.v2_set_value_automatic)
    } else {
        s.transcriptionLanguage.uppercase()
    }

    "about" -> BuildConfig.VERSION_NAME

    else -> null
}

/** What the person is looking at inside Settings v2. */
sealed interface SettingsRoute {
    data object Root : SettingsRoute
    data class Page(val id: String, val highlight: String? = null) : SettingsRoute
}

/**
 * Settings v2 (behind `ui_v2.settings`): the profile card at the top, search that reaches every individual setting
 * and jumps to it, the goals of docs/v2/IA.md with their pages and values, and Sign out. Appearance, Notifications and
 * Setup & health are v2 pages; the others open their classic page ([openClassicPage]) with the same settings and
 * actions. Nothing here changes the view model: choices go through [MainViewModel.updateSettings] as before.
 */
@Composable
fun V2SettingsScreen(
    vm: MainViewModel,
    flags: UiFlags,
    onSwitchToClassic: (Boolean) -> Unit,
    onUpgrade: () -> Unit,
    openClassicPage: @Composable (page: String, onBack: () -> Unit) -> Unit,
    setupPage: @Composable (highlight: String?, onBack: () -> Unit) -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    /** Help v2's source (the phone core's); without one, Help opens its classic page. */
    help: HelpSource? = null
) {
    val settings by vm.settings.collectAsState()
    var route by remember { mutableStateOf<SettingsRoute>(SettingsRoute.Root) }
    var profile by remember { mutableStateOf<ProfileState>(ProfileState.Loading) }
    var attempt by remember { mutableIntStateOf(0) }
    var confirmSignOut by remember { mutableStateOf(false) }
    LaunchedEffect(attempt) {
        profile = ProfileState.Loading
        val account = vm.accountQuietly()
        val storage = vm.storage()
        profile =
            if (account == null && storage == null) ProfileState.Error else ProfileState.Ready(profileOf(account, storage, settings.plan))
    }
    androidx.activity.compose.BackHandler(enabled = route != SettingsRoute.Root) { route = SettingsRoute.Root }
    when (val r = route) {
        is SettingsRoute.Page -> {
            val back = { route = SettingsRoute.Root }
            when (r.id) {
                "appearance" -> AppearancePage(settings, vm::updateSettings, flags, onSwitchToClassic, r.highlight, back)

                "notifications" -> NotificationsPage(settings, vm::updateSettings, r.highlight, back)

                SettingsTree.SETUP -> setupPage(r.highlight, back)

                "calls" -> CallsPage(settings, vm::updateSettings, r.highlight, back)

                "language" -> LanguagePage(vm, settings, r.highlight, back)

                "words" -> WordsPage(vm, settings, r.highlight, back)

                "key" -> KeyPage(vm, settings.plan, back)

                // Help v2 (guides, what's new, contact support); its phone check is the classic help page.
                "help" -> if (help != null) HelpPage(help, openClassicPage, back) else openClassicPage(r.id, back)

                else -> openClassicPage(r.id, back)
            }
        }

        SettingsRoute.Root -> V2SettingsRoot(
            settings = settings,
            profile = profile,
            signedIn = settings.serverUrl != null,
            onUpgrade = onUpgrade,
            onRetryProfile = { attempt++ },
            onOpen = { page, highlight -> route = SettingsRoute.Page(page, highlight) },
            onSignOut = { confirmSignOut = true },
            onBack = onBack,
            modifier = modifier
        )
    }
    if (confirmSignOut) {
        EchoDialog(
            title = stringResource(R.string.v2_set_sign_out_title),
            text = stringResource(R.string.v2_set_sign_out_body),
            confirmLabel = stringResource(R.string.v2_set_sign_out),
            onConfirm = {
                confirmSignOut = false
                vm.unpair()
            },
            dismissLabel = v2Text.cancel,
            onDismiss = { confirmSignOut = false },
            destructive = true
        )
    }
}

/** The root of Settings v2 without the view model (tests and screenshots). */
@Composable
fun V2SettingsRoot(
    settings: AppSettings,
    profile: ProfileState,
    signedIn: Boolean,
    onUpgrade: () -> Unit,
    onRetryProfile: () -> Unit,
    onOpen: (page: String, highlight: String?) -> Unit,
    onSignOut: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    initialQuery: String = ""
) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    var query by rememberSaveable { mutableStateOf(initialQuery) }
    val hits = remember(query, res) { SettingsSearch.search(res, query) }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(v2Text.settings, onBack = onBack)
        LazyColumn(Modifier.weight(1f)) {
            item {
                EchoSearchField(
                    query,
                    { query = it },
                    stringResource(R.string.v2_set_search_settings),
                    Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
                )
            }
            if (query.isNotBlank()) {
                if (hits.isEmpty()) {
                    item {
                        EchoEmptyState(
                            stringResource(R.string.v2_set_no_settings_title, query.trim()),
                            stringResource(R.string.v2_set_no_settings_body),
                            icon = EchoIcons.Search
                        )
                    }
                }
                items(hits, key = { it.item.id }) { hit ->
                    EchoSettingRow(hit.title, subtitle = hit.path, onClick = { onOpen(hit.item.page, hit.item.id) })
                }
                return@LazyColumn
            }
            item { ProfileCardSlot(profile, onUpgrade, onRetryProfile) }
            SettingsTree.goals.forEach { (goal, pages) ->
                item(key = goal.name) { EchoSectionHeader(stringResource(goal.title)) }
                items(pages, key = { it }) { page ->
                    EchoSettingRow(
                        SettingsSearch.pageTitle(res, page),
                        value = pageValue(res, page, settings),
                        onClick = { onOpen(page, null) }
                    )
                }
            }
            if (signedIn) {
                item { EchoSettingRow(stringResource(R.string.v2_set_sign_out), icon = EchoIcons.SignOut, onClick = onSignOut) }
            }
        }
    }
}

@Composable
private fun HelpPage(help: HelpSource, openClassicPage: @Composable (String, () -> Unit) -> Unit, onBack: () -> Unit) {
    var phoneCheck by remember { mutableStateOf(false) }
    if (phoneCheck) {
        openClassicPage("help") { phoneCheck = false }
    } else {
        V2HelpScreen(help, onPhoneCheck = { phoneCheck = true }, onBack = onBack)
    }
}
