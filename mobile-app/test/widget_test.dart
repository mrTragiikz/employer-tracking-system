// Smoke test - the app builds without throwing.
import 'package:flutter_test/flutter_test.dart';
import 'package:rajdoot_field/main.dart';

void main() {
  testWidgets('app builds', (tester) async {
    await tester.pumpWidget(const RajdootApp());
    await tester.pump();
    expect(find.byType(RajdootApp), findsOneWidget);
  });
}
