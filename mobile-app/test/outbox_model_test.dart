import 'package:flutter_test/flutter_test.dart';
import 'package:rajdoot_field/services/outbox.dart';

void main() {
  group('OutboxItem', () {
    test('endpoint + photo field per kind', () {
      final ci = OutboxItem(
        id: '1', kind: OutboxKind.checkin, fields: const {}, photoPath: null,
        createdAt: DateTime(2026),
      );
      expect(ci.endpoint, '/checkin.php');
      expect(ci.photoField, 'odometer_photo');
      expect(ci.isJson, false);

      final vs = OutboxItem(
        id: '2', kind: OutboxKind.visitSave, fields: const {'shop_name': 'Mart'},
        photoPath: null, createdAt: DateTime(2026),
      );
      expect(vs.endpoint, '/visit-save.php');
      expect(vs.photoField, 'shop_photo');
      expect(vs.label, contains('Mart'));

      final vc = OutboxItem(
        id: '3', kind: OutboxKind.visitComplete, fields: const {'visit_id': '0'},
        photoPath: null, createdAt: DateTime(2026),
      );
      expect(vc.endpoint, '/visit-complete.php');
      expect(vc.isJson, true);

      final co = OutboxItem(
        id: '4', kind: OutboxKind.checkout, fields: const {}, photoPath: null,
        createdAt: DateTime(2026),
      );
      expect(co.endpoint, '/checkout.php');
    });

    test('round-trips through json', () {
      final original = OutboxItem(
        id: 'abc',
        kind: OutboxKind.visitSave,
        fields: const {'shop_name': 'Bhat Bhateni', 'lat': '27.7', 'lng': '85.3'},
        photoPath: '/data/outbox/abc.jpg',
        createdAt: DateTime.parse('2026-09-09T10:30:00.000'),
        attempts: 2,
        lastError: 'boom',
      );
      final restored = OutboxItem.fromJson(original.toJson());
      expect(restored.id, original.id);
      expect(restored.kind, original.kind);
      expect(restored.fields, original.fields);
      expect(restored.photoPath, original.photoPath);
      expect(restored.createdAt, original.createdAt);
      expect(restored.attempts, 2);
      expect(restored.lastError, 'boom');
    });
  });
}
