# pfSense FRP Client Package

This is a pfSense package port for managing the FRP client (`frpc`) from the
pfSense web interface.

The package depends on the FreeBSD `net/frp` port for the `frpc` binary. The
pfSense package installs the WebGUI, package metadata, privilege file, and rc.d
wrapper.

## Features

- `Services > FRP Client` menu entry
- dedicated pfSense WebGUI page at `/frp_client.php`
- full-width editor for the complete `frpc.toml`
- configuration validation with `frpc verify` before saving
- manual validation button
- service restart button
- process and connection status based on the running process and recent FRP log
  messages
- `frpc` version display
- link to FRP information
- package registration for pfSense Package Manager
- package logging under `Status > System Logs > Packages`
- detection of console-side changes to `/usr/local/etc/frpc.toml`
- `net/frp` runtime dependency for the `frpc` binary

## Installed Files

Important installed files:

```text
/usr/local/www/frp_client.php
/usr/local/pkg/frp.xml
/usr/local/pkg/frp/frp.inc
/usr/local/etc/rc.d/frpc-pfsense
/usr/local/share/pfSense-pkg-frp/info.xml
/etc/inc/priv/frp.priv.inc
```

`/usr/local/bin/frpc` and `/usr/local/etc/frpc.toml.sample` are installed by
the `net/frp` dependency, not by this pfSense package.

Runtime files:

```text
/usr/local/etc/frpc.toml
/usr/local/etc/rc.conf.d/frpc_pfsense
/var/run/frpc-pfsense.pid
/var/log/frp.log
```

## Configuration Model

pfSense package configuration is stored in `/conf/config.xml`. The WebGUI saves
the FRP client TOML there as base64 so multiline content is preserved.

`/usr/local/etc/frpc.toml` is the generated runtime configuration file used by
`frpc`.

If `/usr/local/etc/frpc.toml` is edited manually from the console, the WebGUI
detects that the runtime file differs from the configuration stored in pfSense.
It shows a warning and a `Load /usr/local/etc/frpc.toml` button. The runtime file
is only imported into pfSense after explicit confirmation and successful
`frpc verify` validation.

## WebGUI Usage

Open:

```text
Services > FRP Client
```

The page provides:

- enable/disable checkbox
- `frpc.toml` editor
- `Save`
- `Validate Configuration`
- `Restart FRP Service`
- service status panel
- `frpc` version
- recent log preview
- links to FRP information and pfSense package logs

Saving validates the TOML first. Invalid configuration is rejected and is not
written to `/conf/config.xml` or `/usr/local/etc/frpc.toml`.

When enabled, saving restarts the service.

## Logging

`frpc` output is written to:

```text
/var/log/frp.log
```

The package metadata registers this log with pfSense, so it appears under:

```text
Status > System Logs > Packages
```

The WebGUI status panel also uses recent FRP log messages to infer whether the
client has connected to the server.

## Build

Place this directory in a pfSense/FreeBSD ports tree:

```text
net/pfSense-pkg-frp
```

Build from the port directory:

```sh
make package
```

The build uses the normal ports dependency mechanism and pulls `net/frp` for the
FRP client binary.

## Development Notes

On some pfSense development images:

- `/usr/include/sys/param.h` may be missing, so `make` may need an explicit
  `OSVERSION`, for example `make OSVERSION=1500029 package`
- older `pkg-static` versions may not support the `pkg create -T` option used by
  newer ports trees; in that case the final package can be created manually from
  the staged files

## Package Manager Visibility

The package uses `info.xml` in pfSense's `pfsensepkgs` format so
`/etc/rc.packages` can register it in `/conf/config.xml`.

When installed from a local `.pkg` file during development, pfSense may show the
binary package as coming from `unknown-repository`. The pfSense Installed
Packages page filters on the pfSense repository name, so a local dev install may
need a repository annotation or installation from a proper pfSense package
repository.
