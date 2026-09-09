allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

// Use Gradle's default build directories (the flutter tool expects the .apk
// under <project>/build/...). The template's "../../build" redirect resolved
// to D:\build on this machine and made the tool unable to find the output.
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
