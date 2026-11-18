package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"
)

// An event only tells the client to re-read the separately signed manifest.
// Never install or trust a version, a download URL or any privileged action
// contained in an event response.
var eventsURL = "https://" + updateHost + "/updates/preview/events.php"

const fallbackInterval = 2 * time.Minute
const reconnectDelay = 20 * time.Second
const minEventInterval = 15 * time.Second

var digestPattern = regexp.MustCompile(`^[a-f0-9]{64}$`)

type updateEvent struct {
	Digest string `json:"digest"`
}

// pollEvent performs bounded long-polling. This is *optional*: shared PHP
// hosting must be allowed to reject long polls; a normal signed-manifest
// fallback remains mandatory even when long polling works.
func pollEvent(ctx context.Context, client *http.Client, endpoint, last string) (string, bool, error) {
	return pollEventAtOrigin(ctx, client, endpoint, last, updateHost)
}

// Used by integration tests with a local TLS server. The production caller
// pins the endpoint to the project update hostname.
func pollEventAtOrigin(ctx context.Context, client *http.Client, endpoint, last, expectedHost string) (string, bool, error) {
	u, err := url.Parse(endpoint)
	if err != nil || u.Scheme != "https" || u.Host != expectedHost || u.Path != "/updates/preview/events.php" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return "", false, errors.New("unzulässige Ereignis-URL")
	}
	if last != "" && !digestPattern.MatchString(last) {
		return "", false, errors.New("ungültiger letzter Ereignisstand")
	}
	q := u.Query()
	q.Set("since", last)
	u.RawQuery = q.Encode()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return "", false, err
	}
	req.Header.Set("Accept", "application/json")
	resp, err := client.Do(req)
	if err != nil {
		return "", false, err
	}
	defer resp.Body.Close()
	switch resp.StatusCode {
	case http.StatusNoContent, http.StatusNotModified:
		return last, false, nil
	case http.StatusServiceUnavailable, http.StatusNotFound, http.StatusTooManyRequests:
		return last, false, fmt.Errorf("Push vorübergehend nicht verfügbar: HTTP %d", resp.StatusCode)
	case http.StatusOK:
	default:
		return last, false, fmt.Errorf("Push: HTTP %d", resp.StatusCode)
	}
	if ct := resp.Header.Get("Content-Type"); !strings.HasPrefix(strings.ToLower(ct), "application/json") {
		return last, false, errors.New("Push: ungültiges Datenformat")
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 1025))
	if err != nil || len(data) > 1024 {
		return last, false, errors.New("Push: Antwort zu groß oder unlesbar")
	}
	var event updateEvent
	if json.Unmarshal(data, &event) != nil || !digestPattern.MatchString(event.Digest) {
		return last, false, errors.New("Push: ungültige Ereigniskennung")
	}
	return event.Digest, event.Digest != last, nil
}

// Update checks are deliberately mandatory at startup, after game exit and
// periodically. Push is a latency optimization, never the source of truth.
func watchUpdatesReliable() {
	// First check runs immediately, including after a missed/offline notification.
	logUpdateCheck("Startprüfung")
	lastFallback := time.Now()
	lastEvent := ""
	wasRunning, _ := wowIsRunning()
	for {
		started := time.Now()
		ctx, cancel := context.WithTimeout(context.Background(), 14*time.Second)
		next, changed, err := pollEvent(ctx, updateHTTPClient(), eventsURL, lastEvent)
		cancel()
		if err == nil {
			lastEvent = next
			if changed {
				logUpdateCheck("Server-Benachrichtigung")
				lastFallback = time.Now()
			}
		}
		running, detectionError := wowRunningChecked()
		// If the Windows process detector fails, do not treat it as "game closed".
		if detectionError == nil && wasRunning && !running {
			logUpdateCheck("WoW beendet")
			lastFallback = time.Now()
		}
		if detectionError == nil {
			wasRunning = running
		}
		if time.Since(lastFallback) >= fallbackInterval {
			logUpdateCheck("Kontrollprüfung (Push-unabhängig)")
			lastFallback = time.Now()
		}
		// A capped event endpoint may be unavailable for hours; the fallback still
		// runs. Backoff also prevents hammering an overwhelmed shared-hosting account.
		if err != nil {
			time.Sleep(reconnectDelay)
		} else if d := minEventInterval - time.Since(started); d > 0 {
			// Shared hosting answers events.php immediately (no long poll):
			// pace the loop so every PC asks at most every 15 seconds.
			time.Sleep(d)
		}
	}
}

func logUpdateCheck(reason string) {
	log.Printf("Banklord update (%s): %s", reason, checkUpdateAndApply())
}
