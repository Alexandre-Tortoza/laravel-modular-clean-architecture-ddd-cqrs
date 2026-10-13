variable "SOURCE_REVISION" {
  default = ""
}
variable "RELEASE_VERSION" {
  default = ""
}
variable "RELEASE_PLATFORM" {
  default = "linux/amd64"
}
variable "RELEASE_DIRECTORY" {
  default = "./image-release"
}

group "release" {
  targets = ["php", "nginx"]
}

target "common" {
  context = "."
  dockerfile = "Dockerfile"
  args = { SOURCE_REVISION = SOURCE_REVISION }
  platforms = [RELEASE_PLATFORM]
  labels = {
    "org.opencontainers.image.revision" = SOURCE_REVISION
    "org.opencontainers.image.version" = RELEASE_VERSION
  }
  attest = ["type=provenance,disabled=true", "type=sbom,disabled=true"]
}

target "php" {
  inherits = ["common"]
  target = "runtime"
  output = ["type=oci,dest=${RELEASE_DIRECTORY}/php.oci.tar"]
}

target "nginx" {
  inherits = ["common"]
  target = "nginx-tls"
  output = ["type=oci,dest=${RELEASE_DIRECTORY}/nginx.oci.tar"]
}
