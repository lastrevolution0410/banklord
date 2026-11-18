package main

import (
	"context"
	"os/exec"
	"syscall"
)

// createNoWindow keeps console tools (tasklist, reg, powershell, taskkill) from
// flashing a console window when the setup or the background updater runs them.
const createNoWindow = 0x08000000

func command(name string, args ...string) *exec.Cmd {
	cmd := exec.Command(name, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: createNoWindow}
	return cmd
}

func commandContext(ctx context.Context, name string, args ...string) *exec.Cmd {
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: createNoWindow}
	return cmd
}
