# Flutter's own rules are applied automatically by the Flutter Gradle plugin.
# These cover the plugins this app uses so R8/minify does not strip them.

# geolocator
-keep class com.baseflow.geolocator.** { *; }

# image_picker
-keep class io.flutter.plugins.imagepicker.** { *; }

# path_provider / shared_preferences use plain method channels - nothing to keep.

# Keep annotations and generic signatures Dio / json need at runtime.
-keepattributes Signature
-keepattributes *Annotation*

# Flutter embedding (defensive - the plugin usually adds these).
-keep class io.flutter.embedding.** { *; }
-dontwarn io.flutter.embedding.**
