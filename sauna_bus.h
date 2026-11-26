// WEKA Saunabus – Hilfsfunktionen für den Master-Betrieb
// 38400 Baud, 8 Datenbits + 9. Bit (1 = Steuerbyte, 0 = Datenbyte), 1 Stopbit.
// Das 9. Bit wird über das Paritätsbit erzeugt (je Byte ODD oder EVEN).
// Jedes Byte außer 0x80 wird vom Empfänger mit 0x06 quittiert.
#pragma once
#include "esphome.h"
#include "driver/uart.h"

namespace sbus {

// Ein Byte mit gewünschtem 9. Bit senden (Sender nur für dieses Byte an)
template <typename U, typename D>
inline void send_byte(U *u, D *de, uint8_t b, bool mark) {
  uart_port_t port = (uart_port_t) u->get_hw_serial_number();
  bool even_ones = (__builtin_popcount(b) % 2) == 0;
  uart_set_parity(port, (even_ones == mark) ? UART_PARITY_ODD : UART_PARITY_EVEN);
  de->turn_on();
  u->write_byte(b);
  u->flush();
  de->turn_off();
}

// Auf ein Byte warten (Timeout in µs)
template <typename U>
inline bool wait_byte(U *u, uint32_t timeout_us, uint8_t *b) {
  uint32_t t = esphome::micros();
  while (esphome::micros() - t < timeout_us) {
    if (u->available() && u->read_byte(b)) return true;
  }
  return false;
}

template <typename U>
inline void restore(U *u) {
  uart_set_parity((uart_port_t) u->get_hw_serial_number(), UART_PARITY_ODD);
}

// Byte senden und ACK erwarten
template <typename U, typename D>
inline bool send_acked(U *u, D *de, uint8_t b, bool mark) {
  uint8_t r;
  send_byte(u, de, b, mark);
  return wait_byte(u, 20000, &r) && r == 0x06;
}

// Register schreiben: [addr 0C 02 reg 0F val 80]
template <typename U, typename D>
inline bool write_reg(U *u, D *de, uint8_t addr, uint8_t reg, uint8_t val) {
  uint8_t b;
  while (u->available()) u->read_byte(&b);
  bool ok = send_acked(u, de, addr, true) && send_acked(u, de, 0x0C, true) &&
            send_acked(u, de, 0x02, false) && send_acked(u, de, reg, false) &&
            send_acked(u, de, 0x0F, true) && send_acked(u, de, val, false);
  if (ok) send_byte(u, de, 0x80, true);
  restore(u);
  return ok;
}

// Register lesen: [addr 0C 02 reg 0E] -> Modul sendet Wert -> wir quittieren 06 -> [80]
// Rückgabe: Wert 0..255, oder -1 bei Fehler
template <typename U, typename D>
inline int read_reg(U *u, D *de, uint8_t addr, uint8_t reg) {
  uint8_t b;
  while (u->available()) u->read_byte(&b);
  bool ok = send_acked(u, de, addr, true) && send_acked(u, de, 0x0C, true) &&
            send_acked(u, de, 0x02, false) && send_acked(u, de, reg, false) &&
            send_acked(u, de, 0x0E, true);
  int val = -1;
  if (ok && wait_byte(u, 20000, &b)) {
    val = b;
    send_byte(u, de, 0x06, true);   // Wert quittieren
    send_byte(u, de, 0x80, true);   // Rahmenende
  }
  restore(u);
  return val;
}

}  // namespace sbus
