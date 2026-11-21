package com.echoledger.mobile.ui.v2.settings

import android.content.res.Resources
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
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
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSearchField
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

    // The values classic's Settings list shows (SettingsScreens.pageValue), from the same settings, in the screen's language.
    "mic" -> res.getString(if (s.micSource == "mic") R.string.v2_set_value_raw_mic else R.string.v2_set_value_automatic)

    "voice" -> res.getString(if (s.dictationMode == "hold") R.string.v2_set_value_hold_to_talk else R.string.v2_set_value_tap_to_start)

    "key" -> res.getString(if (s.plan.transcriptionKey?.set == true) R.string.v2_set_value_added else R.string.v2_set_value_not_added)

    // Not in the plan: the plan's badge, as classic's tag.
    "games" -> s.plan.lockFor("live_translate")?.badge
        ?: s.liveTranslate.game.games.size.takeIf { it > 0 }?.let { res.getQuantityString(R.plurals.v2_set_value_games, it, it) }
        ?: res.getString(R.string.v2_set_value_not_set_up)

    "videos" -> s.plan.lockFor("live_translate")?.badge
        ?: s.liveTranslate.watch.apps.size.takeIf { it > 0 }?.let { res.getQuantityString(R.plurals.v2_set_value_apps, it, it) }
        ?: res.getString(R.string.v2_set_value_not_set_up)

    "plan" -> s.plan.displayName.takeIf { s.plan.name != null || s.plan.key != null }

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
 * and jumps to it, the goals of docs/v2/IA.md with their pages and values, and Sign out. The pages of
 * [SettingsTree.nativePages] are v2 pages; the others open their classic page ([openClassicPage]) with the same settings
 * and actions. Nothing here changes the view model: choices go through [MainViewModel.updateSettings] as before.
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
    help: HelpSource? = null,
    /** A page to open at once (a recording's "Check your key" opens the key page). */
    initialPage: String? = null
) {
    val settings by vm.settings.collectAsState()
    var route by remember(initialPage) { mutableStateOf<SettingsRoute>(initialPage?.let { SettingsRoute.Page(it) } ?: SettingsRoute.Root) }
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

                "captions" -> CaptionsPage(settings, vm::updateSettings, r.highlight, back)

                SettingsTree.SETUP -> setupPage(r.highlight, back)

                "calls" -> CallsPage(settings, vm::updateSettings, r.highlight, back, onSetup = {
                    route =
                        SettingsRoute.Page(SettingsTree.SETUP)
                })

                "language" -> LanguagePage(vm, settings, r.highlight, back)

                "words" -> WordsPage(vm, settings, r.highlight, back)

                "key" -> KeyPage(vm, settings.plan, r.highlight, back)

                "account" -> AccountSettingsPage(settings.serverUrl, r.highlight, openClassicPage, { vm.unpair() }, back)

                "lock" -> AppLockPage(settings, vm::updateSettings, { vm.setWidgetHideTitles(it) }, r.highlight, back)

                // Export or delete my data opens the account's classic page, where they are.
                "privacy" -> PrivacyPage(settings, vm::updateSettings, r.highlight, { page ->
                    route = SettingsRoute.Page(page, if (page == "account") "account.export" else null)
                }, back)

                "storage" -> StoragePage(
                    load = { vm.storage() },
                    planStorage = settings.plan.limits["storage_bytes"],
                    keepHours = settings.trashKeepHours,
                    highlight = r.highlight,
                    onBack = back
                )

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
            // This month's minutes show once: as the bar in Account & plan when the plan has them, else on the card.
            item {
                ProfileCardSlot(
                    profile,
                    onUpgrade,
                    onRetryProfile,
                    showMinutes = settings.plan.usedMinutes == null,
                    upgrade = com.echoledger.mobile.ui.v2.shell.upgradeLook(settings.plan, signedIn),
                    onOpenPlan = { onOpen("plan", null) }
                )
            }
            SettingsTree.goals.forEach { (goal, pages) ->
                item(key = goal.name) { GoalHeader(goal) }
                // Account & plan: this month's minutes first (Home's usage meter, docs/PLAN_V2_PARITY.md section 2).
                if (goal == SettingsGoal.Account) {
                    settings.plan.usedMinutes?.let { used ->
                        item(key = "usage") { UsageMeter(used, settings.plan.monthlyMinutes) { onOpen("plan", "plan.usage") } }
                    }
                }
                items(pages, key = { it }) { page ->
                    EchoSettingRow(
                        SettingsSearch.pageTitle(res, page),
                        value = pageValue(res, page, settings),
                        icon = SettingsTree.pageIcons[page],
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

/** A group's header: its icon, its name and its one line (docs/PLAN_V2_PARITY.md section 5). */
@Composable
private fun GoalHeader(goal: SettingsGoal) {
    val c = EchoV2.colors
    androidx.compose.foundation.layout.Row(
        Modifier.padding(
            start = EchoTokens.Layout.pagePaddingMobile,
            end = EchoTokens.Layout.pagePaddingMobile,
            top = EchoTokens.Space.s24,
            bottom = EchoTokens.Space.s4
        ),
        verticalAlignment = androidx.compose.ui.Alignment.CenterVertically
    ) {
        androidx.compose.material3.Icon(goal.icon, null, Modifier.size(EchoTokens.Icon.md), tint = c.accentText)
        Column(Modifier.padding(start = EchoTokens.Space.s12).semantics(mergeDescendants = true) { heading() }) {
            androidx.compose.material3.Text(stringResource(goal.title), style = EchoV2.type.label, color = c.textPrimary)
            androidx.compose.material3.Text(stringResource(goal.description), style = EchoV2.type.caption, color = c.textSecondary)
        }
    }
}

/** This month's minutes as a bar ("120 of 300 minutes used"); it opens Plan & usage. */
@Composable
fun UsageMeter(used: Long, monthly: Long?, onClick: () -> Unit) {
    val c = EchoV2.colors
    val words = monthly?.let { stringResource(R.string.v2_set_usage_of, used.toInt(), it.toInt()) }
        ?: stringResource(R.string.v2_set_usage_unlimited, used.toInt())
    EchoSettingRow(
        stringResource(R.string.v2_set_usage_title),
        subtitle = words,
        icon = EchoIcons.Plan,
        onClick = onClick
    )
    monthly?.takeIf { it > 0 }?.let { limit ->
        val share = (used.toFloat() / limit).coerceIn(0f, 1f)
        androidx.compose.foundation.layout.Box(
            Modifier
                .padding(
                    start = EchoTokens.Layout.pagePaddingMobile + EchoTokens.Icon.lg + EchoTokens.Space.s16,
                    end = EchoTokens.Layout.pagePaddingMobile
                )
                .fillMaxWidth()
                .height(EchoTokens.Space.s4)
                .background(c.bgSubtle, androidx.compose.foundation.shape.RoundedCornerShape(EchoTokens.Radius.full))
                // Read as a progress bar ("70 percent"), with the words on the row above.
                .semantics {
                    progressBarRangeInfo = androidx.compose.ui.semantics.ProgressBarRangeInfo(share, 0f..1f)
                    contentDescription = words
                }
        ) {
            androidx.compose.foundation.layout.Box(
                Modifier
                    .fillMaxWidth(share)
                    .height(EchoTokens.Space.s4)
                    .background(
                        if (share >=
                            0.9f
                        ) {
                            c.warning
                        } else {
                            c.accent
                        },
                        androidx.compose.foundation.shape.RoundedCornerShape(EchoTokens.Radius.full)
                    )
            )
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
