# Helper entry point runs via app_process / Shizuku UserService (looked up by name).
-keep class com.echoledger.mobile.capture.HelperMain { public static void main(java.lang.String[]); }
-keep class com.echoledger.mobile.capture.CaptureHelperService { *; }
-keep class com.echoledger.mobile.capture.ICaptureHelper* { *; }
-keep class com.echoledger.mobile.capture.ICallListener* { *; }
# kotlinx.serialization
-keepattributes *Annotation*, InnerClasses
-keepclassmembers class com.echoledger.mobile.** { *** Companion; }
-keepclasseswithmembers class com.echoledger.mobile.** { kotlinx.serialization.KSerializer serializer(...); }
# Tink
-dontwarn com.google.errorprone.annotations.**
-dontwarn javax.annotation.**
