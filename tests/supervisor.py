#!/usr/bin/env python3
"""Exercise the real shell supervisor with private paths and a simulated role gate."""
from pathlib import Path
import os
import signal
import subprocess
import tempfile
import time

root = Path(__file__).resolve().parent.parent

def until(condition, timeout=6):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if condition():
            return
        time.sleep(.05)
    raise AssertionError('Supervisor condition timed out')

with tempfile.TemporaryDirectory(prefix='frp-supervisor-test-') as directory:
    folder = Path(directory)
    allowed = folder / 'allowed'
    child = folder / 'child'
    pidfile = folder / 'frpc.pid'
    starts = folder / 'starts'
    child.write_text('#!/bin/sh\necho start >> "' + str(starts) + '"\n'
                     'if [ -f "' + str(folder / 'crash') + '" ]; then exit 1; fi\nexec /bin/sleep 120\n')
    child.chmod(0o700)
    source = (root / 'files/usr/local/libexec/frp-supervisor').read_text()
    source = source.replace('/var/run/frpc-pfsense.pid', str(pidfile))
    source = source.replace('/usr/local/bin/frpc', str(child))
    source = source.replace('/usr/local/bin/php -f /usr/local/pkg/frp/carp.php guard', f'test -f "{allowed}"')
    script = folder / 'supervisor'
    script.write_text(source)

    def run():
        return subprocess.Popen(['/bin/sh', str(script)], stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, start_new_session=True)

    def finish(process):
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        process.wait(timeout=5)

    process = run()
    try:
        until(lambda: process.poll() is not None)
        assert not starts.exists(), 'BACKUP launched a child'
    finally:
        finish(process)

    allowed.touch()
    process = run()
    try:
        until(pidfile.exists)
        child_pid = int(pidfile.read_text())
        allowed.unlink()
        until(lambda: process.poll() is not None)
        assert not pidfile.exists(), 'Role loss left a child PID file'
        try:
            os.kill(child_pid, 0)
        except ProcessLookupError:
            pass
        else:
            raise AssertionError('Role loss left the FRP child alive')
    finally:
        finish(process)

    allowed.touch()
    (folder / 'crash').touch()
    count = starts.read_text().count('start')
    process = run()
    try:
        until(lambda: starts.read_text().count('start') == count + 1)
        until(lambda: not pidfile.exists())
        allowed.unlink()
        until(lambda: process.poll() is not None)
        assert starts.read_text().count('start') == count + 1, 'Role loss during backoff restarted FRP'
    finally:
        finish(process)

    # Shorten only the delay to exercise the unchanged five-exit budget quickly.
    allowed.touch()
    script.write_text(source.replace('sleep 10 &', 'sleep 0.05 &'))
    count = starts.read_text().count('start')
    process = run()
    try:
        until(lambda: process.poll() is not None)
        assert process.returncode == 1, 'Crash exhaustion must report failure'
        assert starts.read_text().count('start') == count + 5, 'CARP guard changed the five-exit restart budget'
    finally:
        finish(process)
print('PASS: supervisor blocks BACKUP startup, stops its child, cancels retry on role loss and preserves the crash budget')
