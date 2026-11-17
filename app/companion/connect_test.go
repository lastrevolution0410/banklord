package companion

import (
	"encoding/base64"
	"testing"
)

func TestParseConnectCode(t *testing.T) {
	tok := "bl1_" + "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
	code := "BL1C:" + base64.RawURLEncoding.EncodeToString([]byte(`{"s":"https://banklord.mgc-nom.net/","g":1,"t":"`+tok+`"}`))
	c, err := parseConnectCode(code)
	if err != nil || c.Server != "https://banklord.mgc-nom.net" || c.Guild != 1 || c.Token != tok {
		t.Fatalf("%+v %v", c, err)
	}
	for _, bad := range []string{"", "BL1C:%%%", "BL1C:" + base64.RawURLEncoding.EncodeToString([]byte(`{"s":"x","g":1,"t":"nope"}`))} {
		if _, err := parseConnectCode(bad); err == nil {
			t.Fatalf("accepted %q", bad)
		}
	}
}
