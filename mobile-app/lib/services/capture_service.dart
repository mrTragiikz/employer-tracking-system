import 'dart:io';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';

/// One GPS fix - what the web forms capture into hidden lat/lng/accuracy
/// inputs. The employee never types or edits these.
class GpsFix {
  GpsFix(this.lat, this.lng, this.accuracyM);
  final double lat;
  final double lng;
  final double? accuracyM;

  String get coordText => '${lat.toStringAsFixed(5)}, ${lng.toStringAsFixed(5)}';
}

class CaptureException implements Exception {
  CaptureException(this.message);
  final String message;
  @override
  String toString() => message;
}

/// Location + camera, shared by the Check In/Out and My Visits screens.
class CaptureService {
  CaptureService._();
  static final CaptureService instance = CaptureService._();

  final _picker = ImagePicker();

  /// Grab one location fix. Throws [CaptureException] with a message that is
  /// safe to show if services are off or permission is denied - the web app
  /// treats a denied fix on check-in as a hard stop, same here.
  Future<GpsFix> getLocation() async {
    final serviceOn = await Geolocator.isLocationServiceEnabled();
    if (!serviceOn) {
      throw CaptureException(
          'Location is turned off. Turn on GPS / Location in your phone settings and try again.');
    }

    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
    }
    if (perm == LocationPermission.denied) {
      throw CaptureException(
          'Location permission was denied. Allow location access to continue.');
    }
    if (perm == LocationPermission.deniedForever) {
      throw CaptureException(
          'Location permission is blocked. Open app settings and allow location access.');
    }

    try {
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );
      return GpsFix(pos.latitude, pos.longitude, pos.accuracy);
    } catch (_) {
      // fall back to the last known fix rather than failing outright
      final last = await Geolocator.getLastKnownPosition();
      if (last != null) {
        return GpsFix(last.latitude, last.longitude, last.accuracy);
      }
      throw CaptureException(
          'Could not get a location fix. Move to an open area and try again.');
    }
  }

  /// Open the camera for one shot. Returns null if the user backed out.
  /// [maxWidth] keeps the upload small (the web uses a canvas re-encode to
  /// ~70 KB; here we lean on image_picker's own resize + quality).
  Future<File?> takePhoto() async {
    final XFile? shot = await _picker.pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: CameraDevice.rear,
      maxWidth: 1600,
      imageQuality: 70,
    );
    if (shot == null) return null;
    return File(shot.path);
  }
}
