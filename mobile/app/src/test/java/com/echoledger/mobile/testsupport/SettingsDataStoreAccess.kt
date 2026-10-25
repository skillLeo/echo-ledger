package com.echoledger.mobile.testsupport

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.MutablePreferences
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.cancel

/**
 * Direct access to the settings DataStore, used to plant malformed values that the public API can never write.
 * The store is a process-wide singleton, so every test binds it to its own application first.
 */
@Suppress("UNCHECKED_CAST")
private fun settingsDataStore(context: Context): DataStore<Preferences> {
    val method = Class.forName("com.echoledger.mobile.data.SettingsStoreKt").getDeclaredMethod("getDataStore", Context::class.java)
    method.isAccessible = true
    return method.invoke(null, context.applicationContext) as DataStore<Preferences>
}

suspend fun Context.settingsDataStoreEdit(block: (MutablePreferences) -> Unit) {
    settingsDataStore(this).edit(block)
}

suspend fun Context.rawSettingsValue(name: String): String? = settingsDataStore(this).edit { }[stringPreferencesKey(name)]

private fun settingsDelegate(): Any {
    val facade = Class.forName("com.echoledger.mobile.data.SettingsStoreKt")
    return checkNotNull(facade.getDeclaredField("dataStore\$delegate").apply { isAccessible = true }.get(null))
}

/**
 * Binds the settings DataStore to [context]. The store is a process-wide singleton bound to the files directory of the
 * first application that touches it, and every test gets a new application and files directory. The old instance is
 * replaced while holding the delegate's own lock, so a late caller from the previous test can never re-create it for
 * the previous files directory in between.
 */
fun bindSettingsDataStore(context: Context) {
    val delegate = settingsDelegate()
    val lock = checkNotNull(delegate.javaClass.getDeclaredField("lock").apply { isAccessible = true }.get(delegate))
    val instance = delegate.javaClass.getDeclaredField("INSTANCE").apply { isAccessible = true }
    synchronized(lock) {
        instance.set(delegate, null)
        settingsDataStore(context)
    }
}

/**
 * Stops the settings DataStore at the end of a test. Coroutines that outlive a test (services, receivers, view models)
 * would otherwise write to a files directory that no longer exists and report an uncaught exception into the next test.
 */
fun closeSettingsDataStore() {
    val delegate = settingsDelegate()
    val instance = delegate.javaClass.getDeclaredField("INSTANCE").apply { isAccessible = true }.get(delegate) ?: return
    findScope(instance, depth = 3)?.cancel()
}

private fun findScope(target: Any, depth: Int): CoroutineScope? {
    if (depth < 0) return null
    val fields = generateSequence<Class<*>>(target.javaClass) { it.superclass }.flatMap { it.declaredFields.asSequence() }
    for (field in fields) {
        if (java.lang.reflect.Modifier.isStatic(field.modifiers)) continue
        field.isAccessible = true
        val value = runCatching { field.get(target) }.getOrNull() ?: continue
        if (value is CoroutineScope) return value
    }
    for (field in fields) {
        if (java.lang.reflect.Modifier.isStatic(field.modifiers) || field.type.isPrimitive) continue
        val value = runCatching { field.get(target) }.getOrNull() ?: continue
        if (value.javaClass.name.startsWith("androidx.datastore")) findScope(value, depth - 1)?.let { return it }
    }
    return null
}
