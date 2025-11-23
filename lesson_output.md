Tuyệt vời! Dựa vào key points bạn cung cấp, tôi sẽ xây dựng một bài học chi tiết về `export default` trong JavaScript, giúp bạn nắm vững kiến thức này một cách toàn diện.

# 📚 Export Default trong JavaScript: Xuất Giá Trị Mặc Định

## 🎯 MỤC TIÊU HỌC TẬP

Sau khi hoàn thành bài học này, bạn sẽ có thể:

1.  Hiểu rõ khái niệm `export default` trong JavaScript.
2.  Sử dụng `export default` để xuất một giá trị mặc định từ một module.
3.  Sử dụng `import` để nhập giá trị mặc định từ một module khác.
4.  Phân biệt sự khác nhau giữa `export default` và `export` thông thường (named export).
5.  Nắm vững các quy tắc và hạn chế khi sử dụng `export default`.
6.  Đổi tên giá trị mặc định khi import để phù hợp với ngữ cảnh sử dụng.

## 💡 CÁC KHÁI NIỆM CHÍNH

*   **Module:** Một file JavaScript chứa các biến, hàm, class, hoặc các thành phần khác, được sử dụng để tổ chức code và tái sử dụng.
*   **Export:** Quá trình đưa các thành phần từ một module ra ngoài để sử dụng trong các module khác.
*   **Import:** Quá trình đưa các thành phần từ một module khác vào để sử dụng trong module hiện tại.
*   **Default Export:** Một cách xuất một giá trị duy nhất từ một module. Giá trị này được coi là giá trị "mặc định" của module đó.
*   **Named Export:** Một cách xuất nhiều giá trị từ một module, mỗi giá trị có một tên cụ thể.

**Giải thích chi tiết:**

*   **`export default` là gì?**

    `export default` là một cú pháp trong JavaScript cho phép bạn chỉ định một giá trị duy nhất là "mặc định" cho một module. Giá trị này có thể là bất kỳ kiểu dữ liệu nào: biến, hàm, đối tượng, class...

*   **Tại sao cần `export default`?**

    Trong một module, thường có một thành phần quan trọng nhất, hoặc một thành phần mà người dùng sẽ sử dụng nhiều nhất. `export default` giúp người dùng dễ dàng tìm thấy và sử dụng thành phần này.

*   **Sự khác biệt giữa `export default` và `export` (named export):**

    *   `export default` chỉ được sử dụng một lần trong một module.
    *   Khi `import` giá trị `default`, bạn có thể đặt tên tùy ý cho nó.
    *   `export` (named export) cho phép xuất nhiều giá trị từ một module, mỗi giá trị có một tên cụ thể.
    *   Khi `import` các giá trị `named`, bạn phải sử dụng đúng tên đã được export.

## 📝 NỘI DUNG CHI TIẾT

### Phần 1: Xuất Giá Trị Mặc Định (Export Default)

Cú pháp:

```javascript
// Khai báo biến
const myVariable = "Đây là biến mặc định";

// Xuất biến mặc định
export default myVariable;

// Hoặc:

// Khai báo hàm
function myFunction() {
  console.log("Đây là hàm mặc định");
}

// Xuất hàm mặc định
export default myFunction;
```

Lưu ý quan trọng:

*   Bạn chỉ có thể có một `export default` trong một module.
*   Không thể sử dụng `export default` cùng dòng với khai báo `const`, `let`, `var`, hoặc `function`. Phải tách riêng khai báo và export.

    Ví dụ (SAI):

    ```javascript
    // SAI: Không được phép
    export default const myVariable = "Giá trị";
    ```

    Ví dụ (ĐÚNG):

    ```javascript
    const myVariable = "Giá trị";
    export default myVariable;
    ```

### Phần 2: Nhập Giá Trị Mặc Định (Import Default)

Cú pháp:

```javascript
// Nhập giá trị mặc định từ một module
import myValue from './myModule.js';

// Sử dụng giá trị đã nhập
console.log(myValue);
```

Điểm quan trọng:

*   Khi `import default`, bạn **không** cần sử dụng dấu ngoặc nhọn `{}`.
*   Bạn có thể đặt tên **tùy ý** cho giá trị được import. Tên này không cần phải trùng với tên đã export.

### Phần 3: Kết Hợp Export Default và Named Export (Lưu ý quan trọng)

Nếu một module chứa cả `export default` và `export` (named export), bạn cần phải `import` chúng theo cách khác nhau.

*   **Import Named Export:**

    ```javascript
    import { myVariable, myFunction } from './myModule.js';
    ```

*   **Import Default Export:**

    ```javascript
    import myDefaultValue from './myModule.js';
    ```

*   **Import cả Named và Default Export:**

    ```javascript
    import myDefaultValue, { myVariable, myFunction } from './myModule.js';
    ```

    Lưu ý: Thứ tự quan trọng!  `myDefaultValue` phải đứng trước.

**Lưu ý ĐẶC BIỆT quan trọng:**  Nếu bạn sử dụng `import * as myModule from './myModule.js'` (import tất cả các export dưới dạng một đối tượng), thì `export default` **sẽ không** được bao gồm trong đối tượng `myModule`.  Bạn vẫn cần import riêng giá trị default nếu muốn sử dụng nó.

### Phần 4: Đổi Tên Khi Import Default

Vì bạn có thể đặt tên tùy ý khi import default, việc "đổi tên" thực chất là đặt một cái tên khác cho giá trị được import.

```javascript
// Export (myModule.js)
export default function calculateTotal(a, b) {
  return a + b;
}

// Import với tên khác (main.js)
import sum from './myModule.js';

// Sử dụng
console.log(sum(5, 3)); // Output: 8
```

Trong ví dụ trên, hàm `calculateTotal` được export default từ `myModule.js`, nhưng khi import vào `main.js`, nó được đặt tên là `sum`.

## 🔍 VÍ DỤ MINH HỌA

**Ví dụ 1: Xuất và nhập một biến**

`moduleA.js`:

```javascript
const message = "Xin chào từ module A!";
export default message;
```

`main.js`:

```javascript
import greeting from './moduleA.js';
console.log(greeting); // Output: Xin chào từ module A!
```

**Ví dụ 2: Xuất và nhập một hàm**

`calculator.js`:

```javascript
function add(a, b) {
  return a + b;
}
export default add;
```

`app.js`:

```javascript
import sum from './calculator.js';
console.log(sum(10, 5)); // Output: 15
```

**Ví dụ 3: Kết hợp Default và Named Export**

`utils.js`:

```javascript
export const PI = 3.14159;

function calculateArea(radius) {
  return PI * radius * radius;
}

export default calculateArea;
```

`index.js`:

```javascript
import area, { PI } from './utils.js';

console.log("Diện tích hình tròn với bán kính 5:", area(5)); // Diện tích hình tròn với bán kính 5: 78.53975
console.log("Giá trị PI:", PI); // Giá trị PI: 3.14159
```

## 📋 CÁC BƯỚC THỰC HIỆN

1.  **Tạo hai file JavaScript:** Ví dụ: `moduleA.js` và `main.js`.
2.  **Trong `moduleA.js`**, khai báo một biến, hàm, hoặc class, và sử dụng `export default` để xuất nó.
3.  **Trong `main.js`**, sử dụng `import` để nhập giá trị từ `moduleA.js`.
4.  **Sử dụng giá trị đã import** trong `main.js`.
5.  **Chạy code** (ví dụ: trong trình duyệt hoặc Node.js) để kiểm tra kết quả.  Đảm bảo rằng bạn đã cấu hình môi trường để hỗ trợ ES modules (ví dụ: thêm `type="module"` vào thẻ `<script>` trong HTML, hoặc sử dụng bundler như Webpack, Parcel, Rollup).

## 💡 TIPS & LƯU Ý

*   **Luôn nhớ:** Chỉ có một `export default` trong một module.
*   **Chọn tên có ý nghĩa:** Khi import default, hãy chọn một cái tên rõ ràng và dễ hiểu, phù hợp với ngữ cảnh sử dụng.
*   **Sử dụng bundler:** Trong các dự án lớn, nên sử dụng bundler để quản lý các module và dependencies một cách hiệu quả.
*   **Kiểm tra lỗi:** Nếu bạn gặp lỗi khi import hoặc export, hãy kiểm tra kỹ cú pháp và đảm bảo rằng các file được tham chiếu đúng cách.
*   **Đọc tài liệu:** Luôn tham khảo tài liệu chính thức của JavaScript (MDN Web Docs) để hiểu rõ hơn về các khái niệm và cú pháp.

## 📌 TÓM TẮT

*   `export default` cho phép xuất một giá trị duy nhất từ một module.
*   Bạn có thể đặt tên tùy ý cho giá trị được import default.
*   Chỉ có một `export default` trong một module.
*   Không thể sử dụng `export default` cùng dòng với khai báo biến hoặc hàm.
*   Nếu một module có cả default và named export, cần import chúng theo cách khác nhau.
*   Nếu dùng `import * as myModule`, export default không được bao gồm.
*   Việc đổi tên khi import default thực chất là đặt tên khác cho giá trị đó.

## ❓ CÂU HỎI ÔN TẬP

1.  `export default` là gì? Tại sao chúng ta cần nó?
2.  Sự khác biệt giữa `export default` và `export` (named export) là gì?
3.  Làm thế nào để import một giá trị default từ một module?
4.  Bạn có thể có bao nhiêu `export default` trong một module?
5.  Nếu một module có cả default và named export, làm thế nào để import cả hai?
6.  Khi nào bạn nên sử dụng `export default` thay vì `export` (named export)?
7.  Giải thích ý nghĩa của việc "đổi tên" khi import default.

Chúc bạn học tốt và áp dụng thành công kiến thức về `export default` trong các dự án JavaScript của mình!