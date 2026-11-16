package com.echoledger.mobile.ui.v2.shell

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.StorageDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonBlock
import com.echoledger.mobile.ui.v2.components.EchoSkeletonCircle
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** The profile card's content: who is signed in, the plan and the month's minutes, and the storage. */
data class Profile(
    val name: String?,
    val email: String?,
    val plan: String?,
    val usedMinutes: Long?,
    val monthlyMinutes: Long?,
    val storageBytes: Long?,
    val recordings: Int?
)

/** What the profile card shows. */
sealed interface ProfileState {
    data object Loading : ProfileState
    data object Error : ProfileState
    data class Ready(val profile: Profile) : ProfileState
}

/** Two initials for a Latin name, the first letter for an Urdu or Arabic one; "?" when there is no name. */
fun initialsOf(name: String?): String {
    val words = name.orEmpty().split(' ').filter { it.isNotBlank() }
    if (words.isEmpty()) return "?"
    val latin = words.first().first().let { it in 'A'..'Z' || it in 'a'..'z' }
    return (if (latin) words.take(2) else words.take(1)).joinToString("") { it.take(1) }.uppercase()
}

fun profileOf(account: AccountDto?, storage: StorageDto?, plan: com.echoledger.mobile.core.PlanState): Profile = Profile(
    name = account?.name,
    email = account?.email,
    plan = plan.badge ?: plan.name,
    usedMinutes = plan.usedMinutes,
    monthlyMinutes = plan.monthlyMinutes,
    storageBytes = storage?.recordingsBytes,
    recordings = storage?.recordingsCount
)

/**
 * Settings from the avatar (behind `ui_v2.shell`): the profile card with the plan, the month's minutes, the storage and
 * Upgrade; "Classic look" while any v2 flag is on (`PUT /api/account/ui`); and every other setting in [onAllSettings].
 */
@Composable
fun V2SettingsHome(
    vm: MainViewModel,
    flags: UiFlags,
    onSwitchToClassic: (Boolean) -> Unit,
    onUpgrade: () -> Unit,
    onAllSettings: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val settings by vm.settings.collectAsState()
    var state by remember { mutableStateOf<ProfileState>(ProfileState.Loading) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(attempt) {
        state = ProfileState.Loading
        val account = vm.accountQuietly()
        val storage = vm.storage()
        state =
            if (account == null && storage == null) ProfileState.Error else ProfileState.Ready(profileOf(account, storage, settings.plan))
    }
    V2SettingsHomeContent(
        state = state,
        flags = flags,
        onSwitchToClassic = onSwitchToClassic,
        onUpgrade = onUpgrade,
        onAllSettings = onAllSettings,
        onRetry = { attempt++ },
        onBack = onBack,
        modifier = modifier
    )
}

/** Settings home without the view model (tests). */
@Composable
fun V2SettingsHomeContent(
    state: ProfileState,
    flags: UiFlags,
    onSwitchToClassic: (Boolean) -> Unit,
    onUpgrade: () -> Unit,
    onAllSettings: () -> Unit,
    onRetry: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    val text = v2Text
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(text.settings, onBack = onBack)
        LazyColumn(Modifier.weight(1f)) {
            item { ProfileCardSlot(state, onUpgrade, onRetry) }
            if (flags.offersClassicSwitch) {
                item {
                    EchoSwitch(
                        checked = flags.classic,
                        onCheckedChange = onSwitchToClassic,
                        label = text.classicLook,
                        supporting = text.classicLookHelp
                    )
                }
            }
            item { EchoSettingRow(text.allSettings, icon = EchoIcons.Settings, onClick = onAllSettings) }
        }
    }
}

/** The profile card in its three states: loading (static skeleton), the account didn't load (banner), or ready. */
@Composable
fun ProfileCardSlot(state: ProfileState, onUpgrade: () -> Unit, onRetry: () -> Unit) {
    val text = v2Text
    val cardPadding = Modifier.padding(
        start = EchoTokens.Layout.pagePaddingMobile,
        end = EchoTokens.Layout.pagePaddingMobile,
        top = EchoTokens.Space.s8,
        bottom = EchoTokens.Space.s8
    )
    when (state) {
        ProfileState.Loading -> EchoCard(cardPadding) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                EchoSkeletonCircle()
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    EchoSkeletonBlock(fraction = 0.5f, height = EchoTokens.Space.s16)
                    EchoSkeletonBlock(fraction = 0.7f)
                }
            }
            EchoSkeletonBlock(fraction = 0.8f)
        }

        ProfileState.Error -> EchoBanner(
            text.accountError,
            EchoBannerKind.Error,
            cardPadding,
            actionLabel = text.tryAgain,
            onAction = onRetry
        )

        is ProfileState.Ready -> ProfileCard(state.profile, onUpgrade, cardPadding)
    }
}

@Composable
private fun ProfileCard(profile: Profile, onUpgrade: () -> Unit, modifier: Modifier) {
    val c = EchoV2.colors
    val text = v2Text
    EchoCard(modifier) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
            EchoAvatar(initials = initialsOf(profile.name ?: profile.email))
            Column(Modifier.weight(1f)) {
                Text(
                    profile.name ?: profile.email ?: text.notSignedIn,
                    style = EchoV2.type.bodyStrong,
                    color = c.textPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
                profile.email?.takeIf { profile.name != null }?.let {
                    Text(it, style = EchoV2.type.metaLatin, color = c.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                profile.plan?.let { EchoStatus(text.plan(it), EchoStatusKind.Accent) }
                if (profile.usedMinutes != null && profile.monthlyMinutes != null) {
                    Text(text.minutesUsed(profile.usedMinutes, profile.monthlyMinutes), style = EchoV2.type.meta, color = c.textSecondary)
                }
                if (profile.storageBytes != null && profile.recordings != null) {
                    Text(
                        text.storageUsed(LibraryModel.size(profile.storageBytes), profile.recordings),
                        style = EchoV2.type.meta,
                        color = c.textSecondary
                    )
                }
            }
            EchoButton(text.upgrade, onClick = onUpgrade)
        }
    }
}
