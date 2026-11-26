# Vosk (голосовое пробуждение «Зак») работает через JNA: нативная библиотека ищет
# классы и поля JNA по исходным именам, поэтому R8 не должен их переименовывать.
-keep class com.sun.jna.** { *; }
-keep class * implements com.sun.jna.** { *; }
-keep class org.vosk.** { *; }
-dontwarn java.awt.**
-dontwarn com.sun.jna.**
