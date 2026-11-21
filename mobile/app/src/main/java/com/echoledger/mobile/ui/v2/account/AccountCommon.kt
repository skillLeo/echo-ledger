package com.echoledger.mobile.ui.v2.account

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.account.AccountActions
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.AvatarCache
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoPersonAvatar
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.initialsOf
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** The account calls the screens use: the phone's own client, or a test's. */
val LocalAccountActions = staticCompositionLocalOf<AccountActions?> { null }

@Composable
fun accountActions(): AccountActions {
    LocalAccountActions.current?.let { return it }
    val app = LocalContext.current.applicationContext
    return remember(app) { AccountActions { (app as? EchoLedgerApp)?.graph?.api() } }
}

/**
 * Shows a toast without waiting for it: a toast's show() lasts as long as the toast does. Launched from this
 * composable's scope, so a page that changes as it confirms (the email's check screen) still shows it.
 */
@Composable
fun rememberSay(): (String) -> Unit {
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    return remember(toasts, scope) { { words: String -> scope.launch { toasts.show(words) } } }
}

/** An account page: its title, Back, and its content scrolling under them. */
@Composable
fun AccountPage(title: String, onBack: () -> Unit, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(title, onBack = onBack)
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = EchoTokens.Space.s32),
            content = content
        )
    }
}

/** The page's padding for text and fields. */
val PagePad: Modifier get() = Modifier.fillMaxWidth().padding(
    horizontal = EchoTokens.Layout.pagePaddingMobile,
    vertical = EchoTokens.Space.s8
)

/** A column of a form's parts, padded as a page. */
@Composable
fun FormColumn(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(PagePad.then(modifier), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12), content = content)
}

/** A line of help under a heading. */
@Composable
fun HelpLine(words: String, modifier: Modifier = Modifier) {
    Text(words, style = EchoV2.type.meta, color = EchoV2.colors.textSecondary, modifier = modifier)
}

/** What went wrong, in plain words, as an error banner; nothing when [problem] is null. */
@Composable
fun ProblemBanner(problem: AccountProblem?, modifier: Modifier = Modifier) {
    val res = LocalContext.current.resources
    problem?.let { EchoBanner(AccountWords.problem(res, it), EchoBannerKind.Error, modifier) }
}

/**
 * A person's picture from its signed [url], kept on the phone by [person] and [version] (so it shows offline and is
 * downloaded once per version), with their initials (or the person icon) while there is none.
 */
@Composable
fun AccountAvatar(
    name: String?,
    url: String?,
    version: Long?,
    person: String,
    modifier: Modifier = Modifier,
    size: Dp = EchoTokens.Component.avatarSize,
    description: String? = null
) {
    val context = LocalContext.current
    val actions = accountActions()
    var picture by remember(person, version) { mutableStateOf<ImageBitmap?>(null) }
    LaunchedEffect(person, version, url) {
        if (version == null) return@LaunchedEffect
        val cache = AvatarCache.of(context)
        val bytes = withContext(Dispatchers.IO) { cache.load(person, version) { url?.let { actions.avatarImage(it) } } }
        picture = bytes?.let { withContext(Dispatchers.Default) { BitmapFactory.decodeByteArray(it, 0, it.size)?.asImageBitmap() } }
    }
    val shown = picture
    val described = if (description != null) Modifier.semantics { contentDescription = description } else Modifier
    if (version != null && shown != null) {
        Image(shown, contentDescription = null, modifier.then(described).size(size).clip(CircleShape), contentScale = ContentScale.Crop)
    } else {
        EchoPersonAvatar(initialsOf(name), modifier.then(described).size(size))
    }
}
