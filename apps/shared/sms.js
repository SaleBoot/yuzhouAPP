export function normalizePhone(value) {
  const phone = String(value || "").trim().replace(/^\+86/, "");
  if (!/^1[3-9]\d{9}$/.test(phone)) throw new Error("请输入11位中国大陆手机号");
  return phone;
}
export const secondsUntil = (deadline, now) => Math.max(0, Math.ceil((deadline - now) / 1000));

export function smsChallenge(post, now = () => Date.now()) {
  return {
    busy: false,
    challengeId: "",
    phone: "",
    purpose: "",
    expiresAt: 0,
    resendAt: 0,
    notice: "",
    invalidate() {
      this.challengeId = "";
      this.phone = "";
      this.purpose = "";
      this.expiresAt = 0;
      this.notice = "";
    },
    async send(phoneInput, purpose) {
      if (this.busy) throw new Error("请求处理中，请稍候");
      const phone = normalizePhone(phoneInput);
      if (!["login", "register", "bind"].includes(purpose)) throw new Error("验证码用途无效");
      if (this.resendAt > now()) throw new Error("请等待倒计时结束后重发");
      this.invalidate();
      this.busy = true;
      // A lost response can follow an accepted SMS; keep a cooldown until its result is known.
      this.resendAt = now() + 60000;
      try {
        const result = await post("/auth/sms", { phone, purpose });
        if (typeof result?.challenge_id !== "string" || !/^[a-f0-9]{64}$/.test(result.challenge_id) || result.purpose !== purpose || result.delivery_status !== "accepted" || !Number.isInteger(result.expires_in) || result.expires_in <= 0 || !Number.isInteger(result.resend_after) || result.resend_after < 0)
          throw new Error("短信返回信息不完整，请稍后重试");
        this.challengeId = result.challenge_id;
        this.phone = phone;
        this.purpose = purpose;
        this.expiresAt = now() + result.expires_in * 1000;
        this.resendAt = now() + result.resend_after * 1000;
        this.notice = "短信平台已受理，请等待验证码；受理不代表已送达。";
        return result;
      } catch (error) {
        if (error.code === 50311) this.resendAt = 0;
        throw error;
      } finally {
        this.busy = false;
      }
    },
    async verify(phoneInput, purpose, code, extra = {}) {
      if (this.busy) throw new Error("请求处理中，请稍候");
      const phone = normalizePhone(phoneInput);
      if (!this.challengeId || this.phone !== phone || this.purpose !== purpose) throw new Error("请为当前手机号和用途重新获取验证码");
      if (this.expiresAt <= now()) throw new Error("验证码已过期，请重新获取");
      if (!/^\d{6}$/.test(code)) throw new Error("请输入6位数字验证码");
      this.busy = true;
      try {
        const result = await post("/auth/sms/verify", {
          challenge_id: this.challengeId, phone, purpose, code,
          ...(purpose === "register" ? { nickname: extra.nickname?.trim() || undefined, invitation_code: extra.invitation_code?.trim() || undefined } : {}),
        });
        if (purpose === "bind" ? result?.bound !== true : typeof result?.token !== "string" || !result.token || !result?.user) throw new Error("验证返回信息不完整，请重新登录或重试");
        this.invalidate();
        return result;
      } finally {
        this.busy = false;
      }
    },
  };
}
