package com.echoledger.mobile.ui.v2.activity

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.SemanticsPropertyKey
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import com.echoledger.mobile.account.ActorPictures
import com.echoledger.mobile.account.AvatarCache
import com.echoledger.mobile.core.PersonInitials
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActorDto
import com.echoledger.mobile.ui.v2.account.accountActions
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.avatarFill
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * Who an Activity item is about (contract 2.0.0-aa, UX-18, lead decision 27), the phone's half of the desktop's
 * lib/activityPerson.ts: whose face takes the kind's icon's place, and in which colour.
 */
object ActivityPeople {
    /**
     * The kinds whose person replaces the icon, the web console's (Activity::PERSON_KINDS) and the desktop's: a
     * recording sent to you, an upgrade asked for. A warning, a failure or a receipt asked for keeps its icon, the
     * only sign of what it is, whoever it is about (unusual_usage, upgrade_receipt_requested).
     */
    val KINDS = setOf("shared_with_you", "upgrade_requested")

    /** The person whose face [item] shows in its icon's place, or null (the kind's icon). */
    fun shownPerson(item: ActivityItemDto): ActorDto? = if (item.kind in KINDS) item.actor else null

    /** Their colour (0-7): the server's `avatar_color`, else its own rule (the id modulo 8), else one seeded from the name. */
    fun colourOf(actor: ActorDto): Int = actor.avatarColor?.takeIf { it in 0..7 }
        ?: actor.id?.takeIf { it >= 0 }?.let { (it % 8).toInt() }
        ?: seedColour(actor.name?.trim().orEmpty())

    /** The desktop's seed (layout.tsx avatarColour): hash × 31 + each code point, lower case, 32 bits unsigned, mod 8. */
    internal fun seedColour(seed: String): Int {
        val text = seed.trim().lowercase(java.util.Locale.ROOT)
        var hash = 0L
        var i = 0
        while (i < text.length) {
            val point = text.codePointAt(i)
            hash = (hash * 31 + point) and 0xFFFFFFFFL
            i += Character.charCount(point)
        }
        return (hash % 8).toInt()
    }
}

/** What an actor's avatar draws, for tests (it is hidden from TalkBack: the row's title names them). */
data class ActorLook(val picture: Boolean, val initials: String?, val colour: Int?)

const val ACTOR_AVATAR_TAG = "activity-actor"

val ActorLookKey = SemanticsPropertyKey<ActorLook>("ActorLook")

/**
 * [actor]'s face in the place of an Activity item's icon: their picture, kept on the phone by server, person and version
 * and shared by every row that shows them ([ActorPictures]), else their initials (one rule on the phone,
 * [PersonInitials]) in white on their colour, else the person icon. A picture the server no longer gives (403, 404), a
 * fetch that fails or bytes that don't draw show the initials quietly. [classic] draws it at the classic list's tile size
 * in the classic theme. Hidden from TalkBack: the row's words stay exactly as they were.
 */
@Composable
fun ActorAvatar(actor: ActorDto, modifier: Modifier = Modifier, size: Dp = EchoTokens.Component.avatarSize, classic: Boolean = false) {
    val context = LocalContext.current
    val actions = accountActions()
    val url = actor.avatarUrl
    val version = actor.avatarVersion
    // Who the picture is of: its address says (`/api/avatars/{person}/{version}`), else the actor's id.
    val person = url?.let { AvatarCache.personOf(it, actor.id?.toString().orEmpty()) }?.takeIf { it.isNotEmpty() }
    // Kept by person and version, not by the signed address (re-signed every hour), and starting from a picture already
    // drawn: a row coming back into view, or re-signed, never flashes its initials.
    var picture by remember(person, version) {
        mutableStateOf(if (person != null && version != null) ActorPictures.shared.peek(person, version) else null)
    }
    LaunchedEffect(person, version, url) {
        if (url == null || version == null || person == null) return@LaunchedEffect
        val origin = actions.origin() ?: return@LaunchedEffect
        ActorPictures.shared.picture(AvatarCache.of(context, origin), origin, person, version, url) { actions.avatarImage(it) }
            ?.let { picture = it }
    }
    val initials = PersonInitials.of(actor.name)
    val colour = ActivityPeople.colourOf(actor)
    val shown = picture
    val look =
        ActorLook(
            picture = shown != null,
            initials = initials.takeIf { shown == null && it.isNotEmpty() },
            colour = colour.takeIf {
                shown ==
                    null
            }
        )
    val tagged = modifier.clearAndSetSemantics {
        testTag = ACTOR_AVATAR_TAG
        this[ActorLookKey] = look
    }
    when {
        shown != null -> Image(shown, contentDescription = null, tagged.size(size).clip(CircleShape), contentScale = ContentScale.Crop)
        classic -> ClassicInitials(initials, colour, tagged.size(size))
        initials.isNotEmpty() -> EchoAvatar(tagged, initials = initials, fill = avatarFill(EchoV2.colors, colour))
        else -> EchoAvatar(tagged, icon = EchoIcons.Person)
    }
}

/** The classic list's circle: the same palette, light or dark as the classic theme is, white initials (or the person icon). */
@Composable
private fun ClassicInitials(initials: String, colour: Int, modifier: Modifier) {
    val dark = MaterialTheme.colorScheme.background.luminance() < 0.5f
    val tokens = if (dark) EchoV2Colors.Dark else EchoV2Colors.Light
    val ring = if (dark) EchoTokens.Dark.avatarRing else EchoTokens.Light.avatarRing
    val ink = if (dark) EchoTokens.Dark.avatarText else EchoTokens.Light.avatarText
    Box(
        modifier.clip(
            CircleShape
        ).background(avatarFill(tokens, colour), CircleShape).border(EchoTokens.Border.hairline, ring, CircleShape),
        contentAlignment = Alignment.Center
    ) {
        if (initials.isNotEmpty()) {
            Text(initials, color = ink, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, maxLines = 1)
        } else {
            androidx.compose.material3.Icon(EchoIcons.Person, contentDescription = null, tint = ink)
        }
    }
}
