package com.echoledger.mobile.e2e.support

/**
 * Marks a test class that only makes sense when an outside script drives it in several steps (for example one that needs the
 * app's process to be ended between two instrumentation runs). The default connected-test run leaves these out.
 */
@Target(AnnotationTarget.CLASS)
@Retention(AnnotationRetention.RUNTIME)
annotation class ScriptDriven
