package com.echoledger.mobile.testsupport

import android.security.keystore.KeyGenParameterSpec
import java.io.InputStream
import java.io.OutputStream
import java.security.Key
import java.security.KeyStore
import java.security.KeyStoreSpi
import java.security.Provider
import java.security.SecureRandom
import java.security.Security
import java.security.cert.Certificate
import java.security.spec.AlgorithmParameterSpec
import java.util.Collections
import java.util.Date
import java.util.concurrent.ConcurrentHashMap
import javax.crypto.KeyGeneratorSpi
import javax.crypto.SecretKey
import javax.crypto.spec.SecretKeySpec

/**
 * A software stand-in for the `AndroidKeyStore` provider so that code written against the Keystore (Tink's
 * Keystore-wrapped master key) can run on a development machine. Keys live in memory for the life of the JVM.
 */
class FakeAndroidKeyStoreProvider : Provider("AndroidKeyStore", 1.0, "In-memory AndroidKeyStore stand-in for tests") {
    init {
        put("KeyStore.AndroidKeyStore", FakeKeyStoreSpi::class.java.name)
        put("KeyGenerator.AES", FakeKeyGeneratorSpi::class.java.name)
    }

    companion object {
        private val keys = ConcurrentHashMap<String, SecretKey>()

        internal fun keys() = keys

        /** Registers the provider unless a real one is present; returns whether this call installed it. */
        fun install(): Boolean {
            if (Security.getProvider("AndroidKeyStore") != null) return false
            Security.addProvider(FakeAndroidKeyStoreProvider())
            return true
        }

        fun uninstall() {
            Security.removeProvider("AndroidKeyStore")
            keys.clear()
        }
    }
}

class FakeKeyStoreSpi : KeyStoreSpi() {
    private val keys get() = FakeAndroidKeyStoreProvider.keys()

    override fun engineGetKey(alias: String, password: CharArray?): Key? = keys[alias]

    override fun engineGetCertificateChain(alias: String?): Array<Certificate>? = null

    override fun engineGetCertificate(alias: String?): Certificate? = null

    override fun engineGetCreationDate(alias: String?): Date? = if (alias != null && keys.containsKey(alias)) Date(0) else null

    override fun engineSetKeyEntry(alias: String, key: Key, password: CharArray?, chain: Array<out Certificate>?) {
        keys[alias] = key as SecretKey
    }

    override fun engineSetKeyEntry(alias: String?, key: ByteArray?, chain: Array<out Certificate>?) = throw UnsupportedOperationException()

    override fun engineSetCertificateEntry(alias: String?, cert: Certificate?) = throw UnsupportedOperationException()

    override fun engineDeleteEntry(alias: String) {
        keys.remove(alias)
    }

    override fun engineAliases(): java.util.Enumeration<String> = Collections.enumeration(keys.keys.toList())

    override fun engineContainsAlias(alias: String): Boolean = keys.containsKey(alias)

    override fun engineSize(): Int = keys.size

    override fun engineIsKeyEntry(alias: String): Boolean = keys.containsKey(alias)

    override fun engineIsCertificateEntry(alias: String?): Boolean = false

    override fun engineGetCertificateAlias(cert: Certificate?): String? = null

    override fun engineStore(stream: OutputStream?, password: CharArray?) = Unit

    override fun engineLoad(stream: InputStream?, password: CharArray?) = Unit
}

class FakeKeyGeneratorSpi : KeyGeneratorSpi() {
    private var alias: String? = null
    private var sizeBits = 256

    override fun engineInit(random: SecureRandom?) = throw UnsupportedOperationException("a KeyGenParameterSpec is required")

    override fun engineInit(params: AlgorithmParameterSpec, random: SecureRandom?) {
        val spec = params as KeyGenParameterSpec
        alias = spec.keystoreAlias
        sizeBits = spec.keySize
    }

    override fun engineInit(keysize: Int, random: SecureRandom?) = throw UnsupportedOperationException("a KeyGenParameterSpec is required")

    override fun engineGenerateKey(): SecretKey {
        val key = SecretKeySpec(ByteArray(sizeBits / 8).also { SecureRandom().nextBytes(it) }, "AES")
        FakeAndroidKeyStoreProvider.keys()[requireNotNull(alias)] = key
        return key
    }
}

@Suppress("unused")
private fun keyStoreAvailable(): Boolean = runCatching { KeyStore.getInstance("AndroidKeyStore").load(null) }.isSuccess
