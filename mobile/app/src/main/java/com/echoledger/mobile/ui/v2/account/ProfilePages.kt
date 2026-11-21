package com.echoledger.mobile.ui.v2.account

import android.content.ActivityNotFoundException
import android.content.Context
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.core.content.FileProvider
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.R
import com.echoledger.mobile.account.AccountFormat
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.AccountResult
import com.echoledger.mobile.account.AvatarCache
import com.echoledger.mobile.account.AvatarEncoder
import com.echoledger.mobile.account.EmailChange
import com.echoledger.mobile.account.FieldCode
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * The camera app's photo for the profile (cache/camera/profile.jpg): deleted before each launch, so an old photo is
 * never read as the new one, and after the camera answers, taken or not.
 */
object CameraPhoto {
    fun prepare(photo: File) {
        photo.delete()
        photo.parentFile?.mkdirs()
    }

    /** The photo's bytes when [taken] (null otherwise, or when it can't be read); the file is gone either way. */
    fun taken(photo: File, taken: Boolean): ByteArray? = try {
        if (taken) runCatching { photo.readBytes() }.getOrNull() else null
    } finally {
        photo.delete()
    }
}

/** The person key the own picture is kept under: the id in its address, else "me". */
fun ownAvatarKey(account: AccountDto): String = AvatarCache.personOf(account.avatarUrl, "me")

/**
 * Profile: the name (`PATCH /api/account`), the picture (chosen or taken, made on the phone into a JPEG without its
 * EXIF, `POST /api/account/avatar`; Remove picture, `DELETE`) and the email, which opens its own page.
 */
@Composable
fun ProfilePage(
    account: AccountDto,
    onChanged: (AccountDto) -> Unit,
    onEmail: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    val res = context.resources
    val actions = accountActions()
    val say = rememberSay()
    val scope = rememberCoroutineScope()
    var name by rememberSaveable(account.name) { mutableStateOf(account.name.orEmpty()) }
    var savingName by remember { mutableStateOf(false) }
    var nameProblem by remember { mutableStateOf<AccountProblem?>(null) }
    var picturing by remember { mutableStateOf(false) }
    var pictureProblem by remember { mutableStateOf<String?>(null) }
    val nameSaved = stringResource(R.string.v2_acct_name_saved)
    val pictureSaved = stringResource(R.string.v2_acct_picture_saved)
    val pictureRemoved = stringResource(R.string.v2_acct_picture_removed)

    fun upload(bytes: ByteArray?) {
        if (bytes == null) return
        picturing = true
        pictureProblem = null
        scope.launch {
            when (val made = withContext(Dispatchers.Default) { AvatarEncoder.encode(bytes) }) {
                AvatarEncoder.Outcome.Unreadable -> pictureProblem = res.getString(R.string.v2_acct_picture_unreadable)

                AvatarEncoder.Outcome.TooSmall -> pictureProblem = res.getString(R.string.v2_acct_picture_too_small)

                is AvatarEncoder.Outcome.Ready -> when (val r = actions.uploadAvatar(made.jpeg)) {
                    is AccountResult.Done -> {
                        val now = account.copy(avatarUrl = r.value.avatarUrl, avatarVersion = r.value.avatarVersion)
                        val origin = actions.origin()
                        val version = r.value.avatarVersion
                        if (origin != null && version != null) {
                            withContext(Dispatchers.IO) { AvatarCache.of(context, origin).put(ownAvatarKey(now), version, made.jpeg) }
                        }
                        onChanged(now)
                        say(pictureSaved)
                    }

                    is AccountResult.Failed -> pictureProblem = AccountWords.problem(res, r.problem)
                }
            }
            picturing = false
        }
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        uri?.let { scope.launch { upload(withContext(Dispatchers.IO) { readPicture(context, it) }) } }
    }
    val photo = remember { File(context.cacheDir, "camera/profile.jpg") }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken ->
        scope.launch {
            val bytes = withContext(Dispatchers.IO) { CameraPhoto.taken(photo, taken) }
            upload(bytes)
        }
    }

    AccountPage(stringResource(R.string.v2_acct_profile), onBack, modifier) {
        FormColumn {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)) {
                AccountAvatar(
                    account.name ?: account.email,
                    account.avatarUrl,
                    account.avatarVersion,
                    ownAvatarKey(account),
                    size = EchoTokens.Space.s64,
                    description = (account.name ?: account.email)?.let { stringResource(R.string.v2_acct_picture_of, it) }
                )
                HelpLine(stringResource(R.string.v2_acct_picture_help), Modifier.weight(1f))
            }
            EchoButton(
                stringResource(R.string.v2_acct_picture_choose),
                {
                    picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                },
                Modifier.fillMaxWidth(),
                kind = EchoButtonKind.Secondary,
                loading = picturing,
                loadingText = stringResource(
                    R.string.v2_acct_picture_saving
                )
            )
            EchoButton(stringResource(R.string.v2_acct_picture_take), {
                try {
                    CameraPhoto.prepare(photo)
                    camera.launch(FileProvider.getUriForFile(context, "${context.packageName}.exports", photo))
                } catch (e: ActivityNotFoundException) {
                    photo.delete()
                    pictureProblem = res.getString(R.string.v2_acct_no_camera)
                } catch (e: IllegalArgumentException) {
                    photo.delete()
                    pictureProblem = res.getString(R.string.v2_acct_no_camera)
                }
            }, Modifier.fillMaxWidth(), kind = EchoButtonKind.Secondary, enabled = !picturing)
            if (account.avatarVersion != null) {
                EchoButton(stringResource(R.string.v2_acct_picture_remove), {
                    picturing = true
                    scope.launch {
                        when (val r = actions.removeAvatar()) {
                            is AccountResult.Done -> {
                                actions.origin()?.let { origin ->
                                    withContext(Dispatchers.IO) { AvatarCache.of(context, origin).forget(ownAvatarKey(account)) }
                                }
                                onChanged(account.copy(avatarUrl = null, avatarVersion = null))
                                say(pictureRemoved)
                            }

                            is AccountResult.Failed -> pictureProblem = AccountWords.problem(res, r.problem)
                        }
                        picturing = false
                    }
                }, kind = EchoButtonKind.Quiet, enabled = !picturing)
            }
            pictureProblem?.let { EchoBanner(it, EchoBannerKind.Error, Modifier.semantics { liveRegion = LiveRegionMode.Polite }) }
        }
        EchoSectionHeader(stringResource(R.string.v2_acct_name))
        FormColumn {
            EchoTextField(
                name,
                {
                    name = it.take(120)
                    nameProblem = null
                },
                label = stringResource(R.string.v2_acct_name),
                supportingText = stringResource(R.string.v2_acct_name_help),
                error = AccountWords.forField(res, nameProblem, "name")
            )
            ProblemBanner(nameProblem?.takeIf { AccountWords.forField(res, it, "name") == null })
            EchoButton(stringResource(R.string.v2_acct_save), {
                savingName = true
                scope.launch {
                    when (val r = actions.rename(name)) {
                        is AccountResult.Done -> {
                            onChanged(r.value)
                            say(nameSaved)
                        }

                        is AccountResult.Failed -> nameProblem = r.problem
                    }
                    savingName = false
                }
            }, Modifier.fillMaxWidth(), enabled = name.isNotBlank() && name.trim() != account.name.orEmpty(), loading = savingName)
        }
        EchoSectionHeader(stringResource(R.string.v2_acct_email))
        EchoSettingRow(
            account.email.orEmpty(),
            value = account.pendingEmail?.let { stringResource(R.string.v2_acct_email_waiting_value) },
            subtitle = stringResource(R.string.v2_acct_email_change),
            onClick = onEmail
        )
    }
}

/** The picked picture's bytes, at most 40 MB read (a picture that big is refused anyway). */
private fun readPicture(context: Context, uri: Uri): ByteArray? = runCatching {
    context.contentResolver.openInputStream(uri)?.use { input ->
        val out = java.io.ByteArrayOutputStream()
        val buffer = ByteArray(64 * 1024)
        var total = 0
        while (true) {
            val n = input.read(buffer)
            if (n < 0) break
            total += n
            if (total > 40 * 1024 * 1024) return@use null
            out.write(buffer, 0, n)
        }
        out.toByteArray()
    }
}.getOrNull()

/** What the email form says about an address before anything is sent (the server checks again). */
fun emailFieldCodes(text: String, now: String?): List<FieldCode> = when {
    text.isBlank() -> emptyList()
    !EmailChange.looksLikeEmail(text) -> listOf(FieldCode("invalid_email"))
    EmailChange.sameAsNow(text, now) -> listOf(FieldCode("same_as_now"))
    else -> emptyList()
}

/**
 * Email: the new address (checked here first), the proof, then "Check your new address" with the 6-digit code. While
 * that screen is open it reads the account every 10 s (and on coming back) until nothing waits, as a link opened in
 * a browser confirms the change too. Cancel the change is `DELETE /api/account/email`.
 */
@Composable
fun EmailPage(
    account: AccountDto,
    proof: ProofAccount,
    onChanged: (AccountDto) -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val res = LocalContext.current.resources
    val actions = accountActions()
    val say = rememberSay()
    var email by rememberSaveable { mutableStateOf("") }
    var asking by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    var checked by remember { mutableStateOf(false) }
    val pending = account.pendingEmail
    AccountPage(stringResource(if (pending != null) R.string.v2_acct_email_check_title else R.string.v2_acct_email), onBack, modifier) {
        if (pending != null) {
            CheckNewAddress(account, onChanged, say)
            return@AccountPage
        }
        FormColumn {
            account.email?.let {
                Text(
                    stringResource(R.string.v2_acct_email_now, AccountFormat.ltr(it)),
                    style = EchoV2.type.body,
                    color = EchoV2.colors.textPrimary
                )
            }
            HelpLine(stringResource(R.string.v2_acct_email_help))
            val local = if (checked) emailFieldCodes(email, account.email) else emptyList()
            EchoTextField(
                email,
                {
                    email = it.trim().take(254)
                    problem = null
                    checked = false
                },
                label = stringResource(R.string.v2_acct_email_new),
                error = AccountWords.fields(res, local) ?: AccountWords.forField(res, problem, "email"),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email)
            )
            ProblemBanner(problem?.takeIf { AccountWords.forField(res, it, "email") == null })
            EchoButton(stringResource(R.string.v2_acct_continue), {
                checked = true
                if (emailFieldCodes(email, account.email).isEmpty()) asking = true
            }, Modifier.fillMaxWidth(), enabled = email.isNotBlank())
        }
    }
    if (asking) {
        ProofSheet(
            account = proof,
            confirmLabel = stringResource(R.string.v2_acct_continue),
            send = { p ->
                when (val r = actions.startEmailChange(email, p)) {
                    is AccountResult.Done -> {
                        onChanged(
                            account.copy(
                                pendingEmail = r.value.pendingEmail ?: email,
                                pendingEmailExpiresAt = r.value.pendingEmailExpiresAt
                            )
                        )
                        null
                    }

                    // The address's own problems (and the lock) belong on the page, not the proof.
                    is AccountResult.Failed -> when (r.problem) {
                        is AccountProblem.Fields, AccountProblem.EmailTaken, is AccountProblem.EmailLocked -> {
                            problem = r.problem
                            null
                        }

                        else -> r.problem
                    }
                }
            },
            onDone = { asking = false },
            onDismiss = { asking = false }
        )
    }
}

/** "Check your new address": the code, Confirm, Cancel the change, and the reading every 10 s. */
@Composable
private fun CheckNewAddress(account: AccountDto, onChanged: (AccountDto) -> Unit, say: (String) -> Unit) {
    val res = LocalContext.current.resources
    val locale = res.configuration.locales[0]
    val actions = accountActions()
    val scope = rememberCoroutineScope()
    var code by remember { mutableStateOf("") }
    var working by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    var looked by remember { mutableStateOf(0) }
    val pending = account.pendingEmail ?: return
    val cancelled = stringResource(R.string.v2_acct_email_cancelled)

    fun finish(now: AccountDto) {
        onChanged(now)
        say(
            if (now.email != account.email &&
                now.email != null
            ) {
                res.getString(R.string.v2_acct_email_done, AccountFormat.ltr(now.email))
            } else {
                cancelled
            }
        )
    }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { looked++ }
    LaunchedEffect(pending, looked) {
        while (true) {
            val now = actions.account().valueOrNull
            if (EmailChange.finished(now)) {
                finish(now!!)
                return@LaunchedEffect
            }
            delay(EmailChange.POLL_MS)
        }
    }
    FormColumn {
        Text(
            stringResource(R.string.v2_acct_email_check_body, AccountFormat.ltr(pending)),
            style = EchoV2.type.body,
            color = EchoV2.colors.textPrimary,
            modifier = Modifier.padding(vertical = EchoTokens.Space.s4)
        )
        AccountFormat.dayAndTime(account.pendingEmailExpiresAt, locale ?: java.util.Locale.getDefault())?.let {
            HelpLine(stringResource(R.string.v2_acct_email_until, AccountFormat.ltr(it)))
        }
        EchoTextField(
            code,
            {
                code = it.filter(Char::isDigit).take(6)
                problem = null
            },
            label = stringResource(R.string.v2_acct_email_code),
            error = problem?.takeIf {
                it == AccountProblem.InvalidCode || it == AccountProblem.Expired
            }?.let { AccountWords.problem(res, it) },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword)
        )
        ProblemBanner(problem?.takeIf { it != AccountProblem.InvalidCode && it != AccountProblem.Expired })
        EchoButton(stringResource(R.string.v2_acct_email_confirm), {
            working = true
            scope.launch {
                when (val r = actions.confirmEmailChange(code)) {
                    is AccountResult.Done -> finish(r.value)
                    is AccountResult.Failed -> problem = r.problem
                }
                working = false
            }
        }, Modifier.fillMaxWidth(), enabled = code.length == 6, loading = working)
        EchoButton(stringResource(R.string.v2_acct_email_cancel), {
            working = true
            scope.launch {
                when (val r = actions.cancelEmailChange()) {
                    is AccountResult.Done -> finish(account.copy(pendingEmail = null, pendingEmailExpiresAt = null))
                    is AccountResult.Failed -> problem = r.problem
                }
                working = false
            }
        }, kind = EchoButtonKind.Quiet, enabled = !working)
        HelpLine(stringResource(R.string.v2_acct_email_watching))
    }
}
