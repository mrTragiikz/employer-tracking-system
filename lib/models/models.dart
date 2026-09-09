/// Plain data classes for the API JSON. Every `fromJson` tolerates a missing
/// or null value - the API is the contract, but a field going absent should
/// never crash a screen.
library;

class Employee {
  Employee({required this.id, required this.name, required this.phone, this.code, this.photoUrl});
  final int id;
  final String name;
  final String phone;
  final String? code;
  final String? photoUrl;

  factory Employee.fromJson(Map<String, dynamic> j) => Employee(
        id: (j['id'] as num?)?.toInt() ?? 0,
        name: (j['name'] as String?) ?? 'Employee',
        phone: (j['phone'] as String?) ?? '',
        code: j['code'] as String?,
        photoUrl: j['photo_url'] as String?,
      );

  String get firstName => name.trim().split(RegExp(r'\s+')).first;
}

class Attendance {
  Attendance({
    required this.id,
    required this.workDate,
    required this.status,
    required this.checkedIn,
    required this.checkedOut,
    this.checkInAt,
    this.checkOutAt,
    this.checkInLat,
    this.checkInLng,
    this.checkOutLat,
    this.checkOutLng,
    this.roadKm = 0,
    this.shopSeconds = 0,
    this.roadSeconds = 0,
    this.activeSeconds,
    this.checkInOdometerKm,
    this.checkOutOdometerKm,
  });

  final int id;
  final String workDate;
  final String status; // open | closed
  final bool checkedIn;
  final bool checkedOut;
  final DateTime? checkInAt;
  final DateTime? checkOutAt;
  final double? checkInLat;
  final double? checkInLng;
  final double? checkOutLat;
  final double? checkOutLng;
  final double roadKm;
  final int shopSeconds;
  final int roadSeconds;
  final int? activeSeconds;
  final double? checkInOdometerKm;
  final double? checkOutOdometerKm;

  static DateTime? _dt(dynamic v) =>
      v is String && v.isNotEmpty ? DateTime.tryParse(v) : null;

  factory Attendance.fromJson(Map<String, dynamic> j) => Attendance(
        id: (j['id'] as num?)?.toInt() ?? 0,
        workDate: (j['work_date'] as String?) ?? '',
        status: (j['status'] as String?) ?? 'open',
        checkedIn: j['checked_in'] == true,
        checkedOut: j['checked_out'] == true,
        checkInAt: _dt(j['check_in_at']),
        checkOutAt: _dt(j['check_out_at']),
        checkInLat: (j['check_in_lat'] as num?)?.toDouble(),
        checkInLng: (j['check_in_lng'] as num?)?.toDouble(),
        checkOutLat: (j['check_out_lat'] as num?)?.toDouble(),
        checkOutLng: (j['check_out_lng'] as num?)?.toDouble(),
        roadKm: (j['road_km'] as num?)?.toDouble() ?? 0,
        shopSeconds: (j['shop_seconds'] as num?)?.toInt() ?? 0,
        roadSeconds: (j['road_seconds'] as num?)?.toInt() ?? 0,
        activeSeconds: (j['active_seconds'] as num?)?.toInt(),
        checkInOdometerKm: (j['check_in_odometer_km'] as num?)?.toDouble(),
        checkOutOdometerKm: (j['check_out_odometer_km'] as num?)?.toDouble(),
      );
}

class Visit {
  Visit({
    required this.id,
    required this.seq,
    required this.shopName,
    required this.areaName,
    required this.lat,
    required this.lng,
    this.arrivedAt,
    this.leftAt,
    this.isOpen = false,
    this.dwellSeconds,
    this.hopRoadKm,
    this.photoUrl,
  });

  final int id;
  final int seq;
  final String shopName;
  final String areaName;
  final double lat;
  final double lng;
  final DateTime? arrivedAt;
  final DateTime? leftAt;
  final bool isOpen;
  final int? dwellSeconds;
  final double? hopRoadKm;
  final String? photoUrl;

  static DateTime? _dt(dynamic v) =>
      v is String && v.isNotEmpty ? DateTime.tryParse(v) : null;

  factory Visit.fromJson(Map<String, dynamic> j) => Visit(
        id: (j['id'] as num?)?.toInt() ?? 0,
        seq: (j['seq'] as num?)?.toInt() ?? 0,
        shopName: (j['shop_name'] as String?) ?? '',
        areaName: (j['area_name'] as String?) ?? '',
        lat: (j['lat'] as num?)?.toDouble() ?? 0,
        lng: (j['lng'] as num?)?.toDouble() ?? 0,
        arrivedAt: _dt(j['arrived_at']),
        leftAt: _dt(j['left_at']),
        isOpen: j['is_open'] == true,
        dwellSeconds: (j['dwell_seconds'] as num?)?.toInt(),
        hopRoadKm: (j['hop_road_km'] as num?)?.toDouble(),
        photoUrl: j['photo_url'] as String?,
      );
}

/// The full read-only profile (bio) from GET /profile.php.
class Profile {
  Profile({
    required this.id,
    required this.name,
    required this.phone,
    required this.isActive,
    this.email,
    this.code,
    this.areaRegion,
    this.address,
    this.dob,
    this.gender,
    this.vehicleType,
    this.documentLabel,
    this.idNumber,
    this.emergencyName,
    this.emergencyContact,
    this.photoUrl,
    this.idPhotoFrontUrl,
    this.idPhotoBackUrl,
    this.joinedOn,
  });

  final int id;
  final String name;
  final String phone;
  final bool isActive;
  final String? email;
  final String? code;
  final String? areaRegion;
  final String? address;
  final String? dob;
  final String? gender;
  final String? vehicleType;
  final String? documentLabel;
  final String? idNumber;
  final String? emergencyName;
  final String? emergencyContact;
  final String? photoUrl;
  final String? idPhotoFrontUrl;
  final String? idPhotoBackUrl;
  final String? joinedOn;

  factory Profile.fromJson(Map<String, dynamic> j) => Profile(
        id: (j['id'] as num?)?.toInt() ?? 0,
        name: (j['name'] as String?) ?? 'Employee',
        phone: (j['phone'] as String?) ?? '',
        isActive: j['is_active'] == true,
        email: j['email'] as String?,
        code: j['code'] as String?,
        areaRegion: j['area_region'] as String?,
        address: j['address'] as String?,
        dob: j['dob'] as String?,
        gender: j['gender'] as String?,
        vehicleType: j['vehicle_type'] as String?,
        documentLabel: j['document_label'] as String?,
        idNumber: j['id_number'] as String?,
        emergencyName: j['emergency_name'] as String?,
        emergencyContact: j['emergency_contact'] as String?,
        photoUrl: j['photo_url'] as String?,
        idPhotoFrontUrl: j['id_photo_front_url'] as String?,
        idPhotoBackUrl: j['id_photo_back_url'] as String?,
        joinedOn: j['joined_on'] as String?,
      );

  String get initials {
    final t = name.trim();
    if (t.isEmpty) return '?';
    return t.length >= 2 ? t.substring(0, 2).toUpperCase() : t.toUpperCase();
  }
}

class Announcement {
  Announcement({required this.id, required this.title, required this.body});
  final int id;
  final String title;
  final String body;

  factory Announcement.fromJson(Map<String, dynamic> j) => Announcement(
        id: (j['id'] as num?)?.toInt() ?? 0,
        title: (j['title'] as String?) ?? '',
        body: (j['body'] as String?) ?? '',
      );
}

/// The Home screen payload.
class HomeData {
  HomeData({
    required this.greeting,
    required this.date,
    required this.attendance,
    required this.visitsToday,
    required this.kmToday,
    this.checkInOdometerKm,
    this.checkOutOdometerKm,
  });

  final String greeting;
  final String date;
  final Attendance? attendance;
  final int visitsToday;
  final double kmToday;
  final double? checkInOdometerKm;
  final double? checkOutOdometerKm;

  factory HomeData.fromJson(Map<String, dynamic> j) {
    final s = (j['stats'] as Map<String, dynamic>?) ?? const {};
    return HomeData(
      greeting: (j['greeting'] as String?) ?? 'Hello',
      date: (j['date'] as String?) ?? '',
      attendance: j['attendance'] is Map<String, dynamic>
          ? Attendance.fromJson(j['attendance'] as Map<String, dynamic>)
          : null,
      visitsToday: (s['visits_today'] as num?)?.toInt() ?? 0,
      kmToday: (s['km_today'] as num?)?.toDouble() ?? 0,
      checkInOdometerKm: (s['check_in_odometer_km'] as num?)?.toDouble(),
      checkOutOdometerKm: (s['check_out_odometer_km'] as num?)?.toDouble(),
    );
  }
}
