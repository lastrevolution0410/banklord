package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

type Install struct {
	Path           string `json:"path"`
	Edition        string `json:"edition"`
	Supported      bool   `json:"supported"`
	Reason         string `json:"reason"`
	AddonCondition string `json:"addon_condition,omitempty"`
	AddonVersion   string `json:"addon_version,omitempty"`
}

var clients = map[string]string{
	"_retail_": "Retail", "_classic_": "Classic Progression / MoP", "_classic_era_": "Classic Era / Hardcore", "_anniversary_": "Classic Anniversary", "_ptr_": "Public Test Realm", "_xptr_": "Experimental PTR", "_beta_": "Beta",
}

// macOS clients are app bundles such as "World of Warcraft Classic.app".
var wowAppRE = regexp.MustCompile(`(?i)^world of warcraft.*\.app$`)
var wowExeRE = regexp.MustCompile(`(?i)^wow(?:classic(?:t|b|-64)?|t|b|-64|-[0-9]+)?\.exe$`)

func candidateInstall(path string) (Install, bool) {
	path = filepath.Clean(strings.TrimSpace(strings.Trim(path, `"`)))
	stat, err := os.Stat(path)
	if err != nil || !stat.IsDir() {
		return Install{}, false
	}
	entries, err := os.ReadDir(path)
	if err != nil {
		return Install{}, false
	}
	exe := false
	for _, entry := range entries {
		if (!entry.IsDir() && wowExeRE.MatchString(entry.Name())) || (entry.IsDir() && wowAppRE.MatchString(entry.Name())) {
			exe = true
		}
	}
	// Modern WoW uses a shared World of Warcraft/Data directory beside
	// _classic_, _retail_, etc. Older standalone installations often keep
	// Data inside the actual client directory. Support BOTH layouts.
	// Do not infer a valid client from Interface/AddOns alone.
	if !exe || !hasGameData(path) {
		return Install{}, false
	}
	edition := "Privatserver / unbekannter Client"
	if n := strings.ToLower(filepath.Base(path)); clients[n] != "" {
		edition = clients[n]
	}
	// Every client is supported: the addon ships a multi-version TOC and the
	// installer pins the exact interface number of the detected build.
	supported := true
	reason := "Gildenbank, Katalog und Bestellungen"
	if strings.EqualFold(filepath.Base(path), "_classic_era_") {
		reason = "Katalog und Bestellungen (Classic Era hat keine Gildenbank)"
	}
	if v := clientVersion(path); v != "" {
		edition += " " + v
	}
	abs, _ := filepath.Abs(path)
	return Install{Path: abs, Edition: edition, Supported: supported, Reason: reason}, true
}

// hasGameData accepts either a standalone client/Data or the shared Data
// directory at the parent of a version-specific game client. File existence
// is an installation hint, not authentication or proof of a particular build.
func hasGameData(clientPath string) bool {
	for _, candidate := range []string{
		filepath.Join(clientPath, "Data"),
		filepath.Join(filepath.Dir(clientPath), "Data"),
	} {
		st, err := os.Stat(candidate)
		if err == nil && st.IsDir() {
			return true
		}
	}
	return false
}

// Normalize a manually selected game root, client folder or Interface/AddOns folder.
func normalizeInput(path string) string {
	path = filepath.Clean(strings.TrimSpace(strings.Trim(path, `"`)))
	name := strings.ToLower(filepath.Base(path))
	if wowExeRE.MatchString(name) {
		path = filepath.Dir(path)
		name = strings.ToLower(filepath.Base(path))
	}
	if name == "addons" {
		path = filepath.Dir(path)
		name = strings.ToLower(filepath.Base(path))
	}
	if name == "interface" {
		path = filepath.Dir(path)
	}
	return path
}

// A selected installation is never guessed: the directory must contain a
// recognizable game executable and game data. Diagnostics explain missing pieces.
func diagnoseChoice(input string) error {
	path := normalizeInput(input)
	st, err := os.Stat(path)
	if os.IsNotExist(err) {
		return fmt.Errorf("Ordner existiert nicht: %s. Über ‚Durchsuchen …‘ den tatsächlichen Spielordner wählen (dort liegt WowClassic.exe oder Wow.exe)", path)
	}
	if err != nil {
		return fmt.Errorf("Ordner nicht lesbar: %s: %w", path, err)
	}
	if !st.IsDir() {
		return fmt.Errorf("Kein Ordner: %s", path)
	}
	entries, err := os.ReadDir(path)
	if err != nil {
		return fmt.Errorf("Ordner nicht lesbar: %w", err)
	}
	hasExe, hasData := false, hasGameData(path)
	for _, e := range entries {
		if !e.IsDir() && wowExeRE.MatchString(e.Name()) {
			hasExe = true
		}
	}
	if !hasExe && !hasData {
		return fmt.Errorf("Kein WoW-Client in diesem Ordner. Wähle den tatsächlichen WoW-Clientordner (gewöhnlich _classic_), nicht nur die Desktop-Verknüpfung")
	}
	if !hasExe {
		return fmt.Errorf("Data ist vorhanden, aber WowClassic.exe/Wow.exe fehlt im gewählten Ordner. Bitte den tatsächlichen Clientordner auswählen")
	}
	if !hasData {
		return fmt.Errorf("WoW-Programm erkannt, aber Data fehlt sowohl im Clientordner als auch im übergeordneten Spielordner. Bitte die tatsächliche Installation prüfen")
	}
	return fmt.Errorf("Ordner nicht erkannt; bitte den konkreten Clientordner wie _classic_ oder _retail_ wählen")
}

func possibleAt(path string) []Install {
	path = normalizeInput(path)
	var result []Install
	if v, ok := candidateInstall(path); ok {
		result = append(result, v)
	}
	for child := range clients {
		if v, ok := candidateInstall(filepath.Join(path, child)); ok {
			result = append(result, v)
		}
	}
	return result
}

// Check likely locations BEFORE any deep walk. A short background scan must
// not miss the standard Program Files installation behind thousands of nodes.
func priorityCandidates(root string) []string {
	p := normalizeInput(root)
	paths := []string{p}
	baseNames := []string{"World of Warcraft", "Games", "Spiele", "Blizzard", "Battle.net", "MMO", "Program Files", "Program Files (x86)"}
	for _, name := range baseNames {
		paths = append(paths, filepath.Join(p, name))
	}
	for _, base := range []string{"Games", "Spiele", "Blizzard", "Battle.net", "MMO", "Program Files", "Program Files (x86)"} {
		paths = append(paths, filepath.Join(p, base, "World of Warcraft"))
	}
	return paths
}

func addAll(got map[string]Install, installs []Install) {
	for _, c := range installs {
		got[strings.ToLower(filepath.Clean(c.Path))] = c
	}
}

// Windows registry values are hints, not a source of authorization.
func registryHints() []string {
	if os.PathSeparator != '\\' {
		return nil
	}
	keys := []string{
		`HKLM\SOFTWARE\Blizzard Entertainment\World of Warcraft`,
		`HKLM\SOFTWARE\WOW6432Node\Blizzard Entertainment\World of Warcraft`,
		`HKCU\SOFTWARE\Blizzard Entertainment\World of Warcraft`,
	}
	var hints []string
	for _, key := range keys {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		out, err := commandContext(ctx, "reg.exe", "query", key).CombinedOutput()
		cancel()
		if err != nil {
			continue
		}
		for _, line := range strings.Split(string(out), "\n") {
			fields := strings.Fields(line)
			if len(fields) > 2 && (strings.EqualFold(fields[0], "InstallPath") || strings.EqualFold(fields[0], "GamePath") || strings.EqualFold(fields[0], "InstallLocation")) {
				if idx := strings.Index(line, "REG_SZ"); idx >= 0 {
					p := strings.TrimSpace(line[idx+len("REG_SZ"):])
					if strings.HasSuffix(strings.ToLower(p), ".exe") {
						p = filepath.Dir(p)
					}
					hints = append(hints, p)
				}
			}
		}
	}
	return hints
}

func typicalRoots() []string {
	hints := runningClientHints()
	hints = append(hints, registryHints()...)
	hints = append(hints, knownClientPaths()...)
	hints = append(hints, platformRoots()...)
	for _, key := range []string{"ProgramFiles", "ProgramFiles(x86)", "USERPROFILE", "PUBLIC", "LOCALAPPDATA"} {
		if p := os.Getenv(key); p != "" {
			hints = append(hints, p)
		}
	}
	for drive := 'C'; drive <= 'Z'; drive++ {
		root := string(drive) + ":\\"
		if st, e := os.Stat(root); e == nil && st.IsDir() {
			hints = append(hints, root)
			for _, sub := range []string{"World of Warcraft", "Games", "Spiele", "MMO", "Battle.net", "Blizzard", "Program Files", "Program Files (x86)", "SteamLibrary", "Users"} {
				hints = append(hints, filepath.Join(root, sub))
				hints = append(hints, filepath.Join(root, sub, "World of Warcraft"))
			}
		}
	}
	return hints
}

type walkNode struct {
	path  string
	depth int
}

var skipDir = map[string]bool{
	"windows": true, "system volume information": true, "$recycle.bin": true, "node_modules": true,
	".git": true, "appdata": true, "temp": true, "temporary internet files": true,
	"cache": true, "caches": true, "packages": true, "winsxs": true, "programdata": true,
}

// Discover scans typical locations then bounded breadth-first directories. It does not
// claim to discover arbitrary unreachable drives and always offers a folder chooser.
func Discover(ctx context.Context, roots []string) []Install {
	found, seen := map[string]Install{}, map[string]bool{}
	queue := make([]walkNode, 0, 1024)
	for _, root := range roots {
		if strings.TrimSpace(root) == "" {
			continue
		}
		for _, path := range priorityCandidates(root) {
			// Never attempt an unbounded recursive scan here.
			addAll(found, possibleAt(path))
		}
		queue = append(queue, walkNode{root, 0})
	}
	visited := 0
	for len(queue) > 0 && visited < 65000 {
		select {
		case <-ctx.Done():
			goto done
		default:
		}
		n := queue[0]
		queue = queue[1:]
		p := filepath.Clean(n.path)
		low := strings.ToLower(p)
		if seen[low] || n.depth > 7 {
			continue
		}
		seen[low] = true
		entries, e := os.ReadDir(p)
		if e != nil {
			continue
		}
		visited++
		// Revisit client candidates quickly without following junctions/symlinked directories.
		addAll(found, possibleAt(p))
		if n.depth == 7 {
			continue
		}
		for _, entry := range entries {
			if !entry.IsDir() || entry.Type()&os.ModeSymlink != 0 || skipDir[strings.ToLower(entry.Name())] {
				continue
			}
			queue = append(queue, walkNode{filepath.Join(p, entry.Name()), n.depth + 1})
		}
	}
done:
	result := make([]Install, 0, len(found))
	for _, v := range found {
		result = append(result, v)
	}
	sort.Slice(result, func(i, j int) bool {
		if result[i].Supported != result[j].Supported {
			return result[i].Supported
		}
		return result[i].Path < result[j].Path
	})
	return result
}

// resolveChoice accepts an exact client folder and, for convenience, a root
// containing precisely one supported game client. Never guesses among many.
func resolveChoice(input string) (string, error) {
	path := normalizeInput(input)
	if _, ok := candidateInstall(path); ok {
		return path, nil
	}
	children := possibleAt(path)
	compatible := []Install{}
	for _, entry := range children {
		if entry.Supported {
			compatible = append(compatible, entry)
		}
	}
	if len(compatible) == 1 {
		return compatible[0].Path, nil
	}
	if len(compatible) > 1 {
		return "", fmt.Errorf("mehrere passende WoW-Versionen gefunden; konkreten Clientordner wählen")
	}
	if len(children) > 0 {
		return "", fmt.Errorf("WoW erkannt, aber kein Clientordner gefunden; bitte den konkreten Ordner wie _classic_ oder _retail_ wählen")
	}
	return "", diagnoseChoice(path)
}

// clientVersion reads the game version ("5.5.4") of a client folder from the
// shared .build.info file, or returns "".
func clientVersion(clientPath string) string {
	products := map[string][]string{"_retail_": {"wow"}, "_classic_": {"wow_classic"}, "_classic_era_": {"wow_classic_era"}, "_anniversary_": {"wow_anniversary", "wow_classic_anniversary"}, "_ptr_": {"wowt"}, "_xptr_": {"wowxptr"}, "_beta_": {"wow_beta"}}
	want := products[strings.ToLower(filepath.Base(clientPath))]
	for _, dir := range []string{filepath.Dir(clientPath), clientPath} {
		data, err := os.ReadFile(filepath.Join(dir, ".build.info"))
		if err != nil {
			continue
		}
		lines := strings.Split(strings.ReplaceAll(string(data), "\r", ""), "\n")
		if len(lines) < 2 {
			continue
		}
		vi, pi := -1, -1
		for i, h := range strings.Split(lines[0], "|") {
			switch strings.ToLower(strings.SplitN(h, "!", 2)[0]) {
			case "version":
				vi = i
			case "product":
				pi = i
			}
		}
		if vi < 0 {
			continue
		}
		for _, line := range lines[1:] {
			cols := strings.Split(line, "|")
			if vi >= len(cols) {
				continue
			}
			match := len(want) == 0 || pi < 0
			for _, w := range want {
				if pi < len(cols) && strings.EqualFold(cols[pi], w) {
					match = true
				}
			}
			if m := versionRE.FindString(cols[vi]); match && m != "" {
				return m
			}
		}
	}
	return ""
}

var versionRE = regexp.MustCompile(`^\d+\.\d+\.\d+`)

// interfaceNumber turns "5.5.4" into "50504" (the TOC "## Interface" value).
func interfaceNumber(version string) string {
	var a, b, c int
	if _, err := fmt.Sscanf(version, "%d.%d.%d", &a, &b, &c); err != nil || b > 99 || c > 99 {
		return ""
	}
	return fmt.Sprintf("%d", a*10000+b*100+c)
}
