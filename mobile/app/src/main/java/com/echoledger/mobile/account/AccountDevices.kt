package com.echoledger.mobile.account

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import androidx.credentials.CreatePublicKeyCredentialRequest
import androidx.credentials.CreatePublicKeyCredentialResponse
import androidx.credentials.CredentialManager
import androidx.credentials.exceptions.CreateCredentialCancellationException
import androidx.credentials.exceptions.CreateCredentialException
import androidx.credentials.exceptions.CreateCredentialInterruptedException
import androidx.credentials.exceptions.CreateCredentialNoCreateOptionException
import androidx.credentials.exceptions.CreateCredentialProviderConfigurationException
import androidx.credentials.exceptions.CreateCredentialUnsupportedException
import com.echoledger.mobile.data.ApiJson
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import java.io.File
import java.security.MessageDigest
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull

/** The authenticator's QR code (the otpauth URI), dark on light, with its quiet zone. */
object QrCode {
    private const val DARK = -0x1000000
    private const val LIGHT = -1

    fun bitmap(text: String, size: Int = 512): Bitmap {
        val matrix = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, size, size, mapOf(EncodeHintType.MARGIN to 2))
        val pixels = IntArray(matrix.width * matrix.height) { i -> if (matrix.get(i % matrix.width, i / matrix.width)) DARK else LIGHT }
        return Bitmap.createBitmap(pixels, matrix.width, matrix.height, Bitmap.Config.ARGB_8888)
    }
}

/** "Open in authenticator app": the otpauth URI for whichever app takes it; false when none is installed. */
object AuthenticatorApp {
    fun intent(uri: String): Intent = Intent(Intent.ACTION_VIEW, Uri.parse(uri)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

    fun open(context: Context, uri: String): Boolean = try {
        context.startActivity(intent(uri))
        true
    } catch (e: ActivityNotFoundException) {
        false
    } catch (e: SecurityException) {
        false
    }
}

/** What adding a passkey on the phone came to. */
sealed interface PasskeyOutcome {
    data class Made(val credential: JsonObject) : PasskeyOutcome

    /** The person closed the phone's passkey sheet. */
    data object Cancelled : PasskeyOutcome

    /** No passkey provider on this phone (no Google Password Manager, an old phone): manage passkeys on the website. */
    data object NoProvider : PasskeyOutcome
    data object Failed : PasskeyOutcome
}

/** Makes a passkey with the phone's Credential Manager from the server's creation options (tests give their own). */
fun interface PasskeyMaker {
    suspend fun create(activity: Context, optionsJson: String): PasskeyOutcome
}

object PhonePasskeyMaker : PasskeyMaker {
    override suspend fun create(activity: Context, optionsJson: String): PasskeyOutcome = try {
        val response = CredentialManager.create(activity).createCredential(activity, CreatePublicKeyCredentialRequest(optionsJson))
        val json = (response as? CreatePublicKeyCredentialResponse)?.registrationResponseJson
        json?.let { PasskeyOutcome.Made(ApiJson.parseToJsonElement(it).jsonObject) } ?: PasskeyOutcome.Failed
    } catch (e: CreateCredentialException) {
        outcomeOf(e)
    } catch (e: IllegalArgumentException) {
        PasskeyOutcome.Failed
    }

    fun outcomeOf(e: CreateCredentialException): PasskeyOutcome = when (e) {
        is CreateCredentialCancellationException, is CreateCredentialInterruptedException -> PasskeyOutcome.Cancelled

        is CreateCredentialNoCreateOptionException,
        is CreateCredentialProviderConfigurationException,
        is CreateCredentialUnsupportedException -> PasskeyOutcome.NoProvider

        else -> PasskeyOutcome.Failed
    }
}

/**
 * Profile pictures kept on the phone by server, person and `avatar_version` (docs/API.md "Avatar URLs"): a new signed
 * address of the same version is the same picture, so it is never downloaded again, and the copy shows offline. A
 * picture whose version changed replaces the old one. [origin] is the paired server's (scheme, host and port): person
 * 7 of one server is never shown for person 7 of another. Every picture is deleted on sign-out and on a new sign-in
 * ([clearAll]).
 */
class AvatarCache(private val dir: File, private val origin: String) {
    private fun safe(person: String): String =
        MessageDigest.getInstance("SHA-256").digest("$origin\n$person".toByteArray()).joinToString("") { "%02x".format(it) }.take(24)

    private fun file(person: String, version: Long) = File(dir, "${safe(person)}_$version.img")

    fun get(person: String, version: Long): ByteArray? = file(person, version).takeIf { it.isFile }?.readBytes()

    /**
     * Keeps [bytes] as [person]'s picture of [version], whole or not at all: written beside it first and then renamed
     * (atomic in one folder), so a reader never sees half a file; the older versions go only after the rename.
     */
    fun put(person: String, version: Long, bytes: ByteArray) {
        dir.mkdirs()
        val target = file(person, version)
        val draft = File(dir, "${target.name}.${System.nanoTime()}.tmp")
        draft.writeBytes(bytes)
        if (!draft.renameTo(target)) {
            target.delete()
            if (!draft.renameTo(target)) {
                draft.delete()
                return
            }
        }
        dir.listFiles { f ->
            f.name.startsWith(safe(person) + "_") && f.name != target.name && !f.name.endsWith(".tmp")
        }?.forEach { it.delete() }
    }

    fun forget(person: String) {
        dir.listFiles { f -> f.name.startsWith(safe(person) + "_") }?.forEach { it.delete() }
    }

    /** The picture of [version]: the kept copy, else downloaded once with [fetch] and kept. */
    suspend fun load(person: String, version: Long, fetch: suspend () -> ByteArray?): ByteArray? =
        get(person, version) ?: fetch()?.also { put(person, version, it) }

    companion object {
        private fun dir(context: Context) = File(context.cacheDir, "avatars")

        fun of(context: Context, origin: String) = AvatarCache(dir(context), origin)

        private val cleared = java.util.concurrent.CopyOnWriteArrayList<() -> Unit>()

        /** [forget] runs with every [clearAll] (the pictures kept in memory, [ActorPictures]). */
        fun onClearAll(forget: () -> Unit) {
            cleared += forget
        }

        /**
         * Sign-out, or a sign-in that may be another account: no one's picture stays on the phone. The pictures in memory
         * go first, so a load running in between can't write the old account's picture back into a folder made again.
         */
        fun clearAll(context: Context) {
            cleared.forEach { it() }
            dir(context).deleteRecursively()
        }

        /** The server an avatar address is on, as [com.echoledger.mobile.net.ApiClient.origin] writes it; null for no address. */
        fun originOf(url: String?): String? = url?.toHttpUrlOrNull()?.let { "${it.scheme}://${it.host}:${it.port}" }

        /** The person an avatar address belongs to (`/api/avatars/{user}/{version}`), or [fallback]. */
        fun personOf(url: String?, fallback: String): String =
            url?.let { Regex("""/api/avatars/([^/?#]+)/""").find(it)?.groupValues?.get(1) } ?: fallback
    }
}
