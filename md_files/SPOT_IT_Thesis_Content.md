# S.P.O.T.-IT: An IoT Integrated Surveillance System for Laboratory Item Tracking and Lost and Found Management inside DLSU-D MLH Laboratory Rooms

**A Thesis Presented to the Faculty of Computer Engineering**  
**College of Engineering, Architecture and Technology**  
**De La Salle University - Dasmariñas**  
**Dasmariñas City, Cavite**  

*In Partial Fulfillment of the Requirements for the Degree of Bachelor of Science in Computer Engineering*  

- **Ryan Robert C. Diosana**  
- **Yiannis L. Pedrozo**  
- **James Jacob E. Sabio**  

**May, 2026**

---

## TABLE OF CONTENTS
- [Chapter 1: Introduction](#chapter-1-introduction)
  - [1.1 Background of the Study](#11-background-of-the-study)
  - [1.2 Objectives of the Study](#12-objectives-of-the-study)
  - [1.3 Significance of the Study](#13-significance-of-the-study)
  - [1.4 Scope and Limitation of the Study](#14-scope-and-limitation-of-the-study)
  - [1.5 Conceptual Framework](#15-conceptual-framework)
  - [1.6 Definition of Terms](#16-definition-of-terms)
- [Chapter 2: Review of Related Literature and Studies](#chapter-2-review-of-related-literature-and-studies)
  - [2.1 Campus Asset and Lost and Found Management](#21-campus-asset-and-lost-and-found-management)
  - [2.2 Image Processing for State Change Detection](#22-image-processing-for-state-change-detection)
  - [2.3 IoT Integration and Centralized Web Platforms](#23-iot-integration-and-centralized-web-platforms)
  - [2.4 Hardware Deployment Strategy](#24-hardware-deployment-strategy)
  - [2.5 Relevancy to the Study](#25-relevancy-to-the-study)
- [Chapter 3: Research Design and Methodology](#chapter-3-research-design-and-methodology)
  - [3.1 Methodological Framework](#31-methodological-framework)
  - [3.2 Data Gathering Procedure](#32-data-gathering-procedure)
  - [3.3 Research Design](#33-research-design)
  - [3.4 Research Instruments](#34-research-instruments)
  - [3.5 Testing Methodology](#35-testing-methodology)
  - [3.6 System Process and Design](#36-system-process-and-design)
  - [3.7 System Components](#37-system-components)
  - [3.8 System Design](#38-system-design)
  - [3.9 Selection of Samples](#39-selection-of-samples)
  - [3.10 Ethical Considerations](#310-ethical-considerations)
- [References](#references)

---

## CHAPTER 1 INTRODUCTION

### 1.1 Background of the Study
Within university campuses, shared facilities such as laboratories are particularly susceptible for item loss and misplacement. Unlike general public spaces, laboratory rooms house institutionally owned equipment, which are monitors, keyboards, and system units related to designated specific lab rooms, that are assigned as fixed positions and expected to remain intact even across multiple laboratory sessions and student usage. When these items are moved, taken, or left unaccounted for, the absence is often not noticed until a later class attempts to use the equipment or even a scheduled inventory check. DeHoratius and Raman (2008) noted that in environments where shared resources are accessed by multiple users, weak monitoring systems significantly increase the risk of item loss and inventory inaccuracy. This concern is directly applicable to university laboratory settings, where equipment accountability depends heavily on manual verification by laboratory personnel.

At De La Salle University-Dasmariñas, an interview was conducted with a CEAT laboratory technician, confirmed that the current monitoring process for laboratory items is entirely manual and traditional. Item records are updated per semester, and physical verification relies on staff walkthroughs, traditionally, rather than continuous automated monitoring. They have further noted that the existing CCTV coverage inside laboratory rooms is insufficient, as it limits the ability of personnel to remotely verify item status without conducting manual inspection procedures. Laboratory rooms are locked after each class session, and a housekeeper must accompany any person who needs to retrieve an item. These conditions indicate that the current monitoring setup is reactive rather than preventive, as issues are only identified after they are reported or physically observed.

Additionally, the lost and found process at the institutional level faces similar inefficiencies. An interview with the Director of the Student Welfare and Formation Office (S.W.A.F.O.) at DLSU-D, have identified the primary challenge as the high rate of item reporting and low rate of item claiming. Items accumulate in storage because neither finders nor owners consistently engage with the existing manual process. Records are maintained through physical logbooks, and staff must monitor social media platforms such as the digital campus Freedom Wall to match items with potential owners. The absence of a centralized digital system means that information is fragmented, response times are delayed, and the chain of custody between item detection and item recovery is difficult to maintain.

In recent years, advances in automated surveillance have introduced technologies capable of monitoring indoor environments without requiring continuous human supervision. Research has demonstrated that camera-based systems using image processing techniques can detect objects and identify state changes in monitored spaces according to Qasim et al., 2023; Veluri et al., 2024. Background subtraction and frame differencing, in particular, are established methods for detecting when objects appear or disappear relative to a reference state (Hussin et al., 2012). These techniques are computationally lightweight and do not require machine learning models, making them suitable for deployment in resource-constrained environments such as a campus laboratory operating on a local network infrastructure.

Despite these advancements, most existing surveillance systems are developed for security purposes and are not designed for localized item tracking or inventory monitoring. Digital lost and found platforms such as CampusTrace (Abhiram et al., 2024) improved the organization of item reports but still depend on users actively submitting a report. If no report is filed, the system produces no record. This reliance on voluntary human participation remains the primary limitation of existing digital recovery systems, as noted by Caw-it et al. (2025), who found that such platforms fail when users do not actively engage with them.

This study addresses the gap between automated visual monitoring and localized laboratory item management at DLSU-D. S.P.O.T.-IT proposes a prototype IoT-integrated CCTV-based monitoring system that uses classical image processing to detect item count deviations inside selected CEAT laboratory rooms. Rather than depending on personnel or students to report a discrepancy, the system continuously compares the live count of detectable items against a staff-registered baseline. Any deviation, whether an item is removed or an unexpected item appears, is automatically flagged, recorded with a timestamp and snapshot, and displayed on a centralized web-based dashboard. This approach reduces dependence on manual monitoring, supports laboratory personnel in maintaining equipment accountability, and provides an organized digital record for lost-and-found management within the CEAT Building.

### Statement of the Problem
Lost items and lab equipment are still being monitored in CEAT using a manual reporting system, a handwritten report, and manual inspection of CCTV footage. This can lead to delays in the discovery, documentation, verification, and recovery of lost or misplaced items. Current CCTV systems can be used to provide visual data, but this is primarily for equipment security review and not to automatically monitor equipment movement or item-related state changes.

Equipment are expected to remain in their designated locations inside laboratory rooms. Items can still be moved, misplaced, be borrowed, or be left out after using them, however. Without continuous monitoring, these deviations may remain unnoticed until manual inspection is conducted. The consistency of visual monitoring also may be impacted by limited coverage of the camera, blind spots, and room activity.

Digital lost and found systems and surveillance systems already exist, but many systems are still based on reporting by users or general security monitoring.

In particular, this study aims to answer the following questions:
1. How can a CCTV-based monitoring setup be designed to monitor predefined laboratory equipment zones inside selected CEAT laboratory rooms?
2. How can frame differencing, reference frames, and Regions of Inspection be utilized to detect equipment deviations inside laboratory rooms?
3. How can the system support recalibration when laboratory layouts or equipment arrangements are intentionally modified?
4. How can detection events and related monitoring data be recorded and stored in a centralized database system?
5. What benefit can be gained from a central web-based dashboard for authorised personnel to access, validate and control any missing, lost, found or claimed items?

---

### 1.2 Objectives of the Study

#### General Objective:
The general objective of this study is to develop a prototype IoT-integrated CCTV-based monitoring system capable of detecting item count deviations inside selected CEAT laboratory rooms, supported by a microservices web-based dashboard for laboratory item tracking and lost and found management.

#### Specific Objectives:
1. To design a surveillance setup that ensures adequate visual coverage of selected CEAT laboratory rooms by utilizing the appropriate IP CCTV camera, placement, and configuration.
2. To develop a visual detection system capable of detecting item count deviations using background subtraction and contour-based counting techniques.
3. To design a microservices database for the organized storage of detection records, including visual snapshots, timestamps, room identification, baseline counts, and deviation values.
4. To integrate an automated synchronization process between the detection system, centralized database, and web-based dashboard for detected deviation alerts and notifications.
5. To assess the functionality and detection accuracy of the proposed system under varying laboratory room conditions.

---

### 1.3 Significance of the Study
- **DLSU-D Students:** The proposed S.P.O.T.-IT system may benefit DLSU-D students by providing a centralized platform where unattended or recovered personal belongings may be monitored and reported.
- **CEAT Faculty Members and Staff:** Faculty members and staff within the CEAT Building may benefit from the system through improved monitoring of unattended objects inside lecture rooms and shared facilities.
- **CEAT Laboratory Personnel and Dispensing Staff:** Laboratory personnel and dispensing staff may benefit from the proposed system through additional monitoring support for laboratory equipment and room inventories.
- **CEAT Security Personnel:** Security personnel may benefit from the proposed system through organized monitoring records containing timestamps, room locations, and visual snapshots of detected monitoring events.
- **Student Welfare and Formation Office (SWAFO):** Instead of relying only on traditional methods such as letters, emails, or manual coordination, the web-based platform may help authorized personnel update item records, view item status, and coordinate verification more efficiently.
- **De La Salle University - Dasmariñas:** The university institution may benefit from the study by exploring how CCTV surveillance, image processing, and IoT-integrated monitoring technologies may support campus lost and found management and localized monitoring operations within university facilities.
- **Future Researchers:** Future researchers may benefit from this study as a reference for developing similar monitoring systems involving computer vision, IoT integration, environmental state change detection, and centralized web-based monitoring platforms.

---

### 1.4 Scope and Limitation of the Study

#### 1.4.1 Scope:
This study focuses on developing an IoT-integrated CCTV-based monitoring system for laboratory item tracking and lost and found management inside selected laboratory rooms of the Maria Salome Llanera Hall, also known as the CEAT Building, at De La Salle University-Dasmariñas.

The surveillance monitoring system is designed to provide visual coverage of selected laboratory rooms through strategic camera placement to maximize equipment visibility and minimize blind spots. The detection process uses standard image processing techniques, including background subtraction, frame differencing, and ROI-based comparison, to analyze live camera footage and identify visual state changes within the monitored areas. The system operates using ROI-based presence and count deviation detection. Authorized laboratory personnel register the expected equipment zones and baseline item count for each monitored room prior to active monitoring. During monitoring, the system compares the live camera frame with the registered reference state. If an item is no longer detected within its assigned inspection zone, or if the detected item count deviates from the baseline, the system flags the event as a possible visual state-change. However, if the item is only slightly misaligned but still visible within the acceptable inspection area, the event may be disregarded or marked as no significant change.

The primary monitored items within the selected laboratory rooms are visible laboratory equipment and items registered within assigned inspection zones or expected workstation areas, allowing minor position adjustments while still flagging significant movement, removal, or unexpected count changes for staff verification. The system includes a centralized database and web-based dashboard for storing detection records, snapshots, timestamps, room locations, equipment labels or inspection zone labels, notification status, and staff verification updates. The system will be evaluated under varying conditions, including different lighting levels, camera positions, room activity, and equipment placement inside the selected rooms.

#### 1.4.2 Delimitation:
The system relies on clear visual data captured by the installed CCTV camera. Detection performance may be affected by poor lighting, glare, camera distance, camera angle, physical obstruction, and items placed outside the camera's field of view or predefined Regions of Inspection. If a monitored item is fully covered or hidden, the system may not be able to verify its presence or absence because the detection process depends on visible changes between the live footage and the registered reference state.

The system detects ROI-based presence changes and count deviations, but it does not independently classify the specific type, brand, ownership, or identity of individual items. Equipment labels are based on the registered inspection zones or baseline setup configured by authorized laboratory personnel. Physical verification and confirmation of whether an item is missing, misplaced, borrowed, recovered, or intentionally moved remain the responsibility of authorized personnel through the web-based dashboard.

To prevent unnecessary false notifications, the system focuses on significant visual changes within the defined inspection zones and ignores very small or irrelevant objects such as crumpled paper, paper scraps, or trash. The system also depends on a stable local network connection for real-time synchronization between the detection module and the web-based dashboard. Network interruptions may delay the transmission of notifications and dashboard updates. Continuous video processing also requires sufficient processing capacity from the local processing unit, so dashboard notifications may experience slight delays.

Lastly, the system operates as an automated monitoring and documentation support tool. It cannot determine human intent, such as whether an item was stolen, borrowed, or accidentally misplaced. The system only flags possible visual state-change events and provides supporting records such as snapshots, timestamps, room location, inspection zone labels, and status updates for review and confirmation by authorized personnel.

---

### 1.5 Conceptual Framework
* **INPUT:** Dual IP CCTV Camera Video Feeds, Baseline Reference Images, Room Type Configuration, WiFi / Local Internet Connection, User Login Credentials
* **PROCESS:** Item Count Deviation Detection (Background Subtraction and Contour-Based Counting), Reference Frame Validation, Web Authentication and Role-Based Access Control, Centralized Database Synchronization
* **UOPUT:** Automated alerts for the unattended or missing items, Captured Snapshot Images, Timestamp and Room Logs, Centralized Database Records, Updated Web Dashboards

---

### 1.6 Definition of Terms
- **Background Subtraction:** The object detection method used in the study to find new or unattended objects by comparing the current live frame from the camera to an empty reference frame of the room that has been saved.
- **Computer Vision:** Enables computers to understand and analyze images and videos.
- **Count Deviation:** A condition that occurs when the number of items detected in the live camera frame differs from the registered baseline count for that room.
- **Image Processing:** The analytical method that the system watches surveillance footage by noticing changes and movement in pixels.
- **Contour-Based Counting:** A detection method used in this study to count the number of visually distinct objects present in a camera frame.
- **Internet of Things (IoT):** A broad network composed of many interconnected devices that communicate with each other through the internet for the purpose of collecting and exchanging data.
- **Missing Object:** Any item or piece of equipment that was clearly present in the original reference frame of the system, but is eventually no longer detected by the lens of the camera during the monitoring of a laboratory setting.
- **Reference Frame:** The primary baseline image of the empty lecture room or of the fully equipped laboratory room that is used by the system as the basis for the detection of any state changes.
- **Registered Baseline Count:** The expected number of items for a specific laboratory room, recorded in the centralized database by authorized laboratory personnel prior to monitoring.

---

## CHAPTER 2 REVIEW OF RELATED LITERATURE AND STUDIES

### 2.1 Campus Asset and Lost and Found Management
#### 2.1.1 Traditional Lost and Found Processes
When a school lacks a formal way to handle lost items, students and staff usually turn to groups on WhatsApp or Facebook. Siok and Cia (2023) pointed out that these manual methods have low recovery rates and high risks of giving items to the wrong person. S.P.O.T.-IT addresses this by using CCTV surveillance and background subtraction to detect left-behind items in real time.

#### 2.1.2 Laboratory Inventory Monitoring
Tan and Chong (2023) highlighted the risk of fraud in lost and found systems, proposing security question forms to ensure rightful claims. Russel and Selvaraj (2023) researched how to detect abandoned objects by recognizing when they are no longer carried. Alam et al. (2025) developed specialized image datasets of laboratory equipment (`LabEquipVis`), proving that automated object detection eliminates manual inventory errors.

#### 2.1.3 The Shift to Digital Automated Systems
Studies on digital platforms like *CampusTrace* (Abhiram et al., 2024), localized systems by Cartago et al., and web apps by Kanjalkar et al. (2024) highlight the transition from physical logbooks to web-based tracking. However, Caw-it et al. (2025) noted that digital platforms still fail if users do not actively engage or if network connections are unstable—a core justification for S.P.O.T.-IT's automated CCTV approach.

### 2.2 Image Processing for State Change Detection
#### 2.2.1 Object Detection in Surveillance
Zhou et al. (2023) introduced *LostNet* for image matching. Nimma et al. (2025) proposed hybrid models combining YOLOv8. Walee (2026) and Marique et al. (2025) highlighted real-time edge computing processing speeds exceeding 30 FPS. Yechevskyi and Sotnik (2024) explored geometric properties using Canny and Sobel edge detection algorithms.

#### 2.2.2 Background Subtraction and Frame Differencing
Hussin et al. (2012) and Qureshi (n.d.) explored background subtraction and grayscale filtering. Kaur and Singh (2023) utilized three-frame differencing for precise binary masks. Hsia et al. (2023) calculated exact object coordinates for camera PTZ control via pixel subtraction.

#### 2.2.3 Unattended vs. Missing Object Logic
Sabelnikov (2025) and Zhou & Xu (2024) discussed adaptive dual background modeling. Yilmazer and Karakose (2025) used DeepSORT to track physical distances between owners and bags. Dimić et al. (2024) implemented Region of Interest (ROI) mapping to ignore peripheral motion.

#### 2.2.4 Environment Factors in Visual Detection
Qasim et al. (2024) noted that lighting changes and glare break basic detection. Wang & Shao (2024) analyzed occlusion challenges. Pastukh and Berezky (2024) proposed adaptive background update algorithms to handle intentional layout changes.

### 2.3 IoT Integration and Centralized Web Platforms
- **Camera Applications:** Yan et al. (2022) developed *CamFi*. Van Den Hengel et al. (2009) emphasized 3D modeling for occlusions. CheckVideo (2026) specified optimal downward camera tilts ($15^\circ$ to $45^\circ$) and mounting heights ($\ge 8$ feet).
- **IoT-Based Smart Registration:** Bienvenu (2026), Panchbhai et al. (2024), and Juaneza (2024) highlighted automated attendance and smart room technologies using ESP32 microcontrollers. Magtibay et al. (2021) demonstrated IoT energy monitoring in DLSU Lipa.
- **Web-Based Management Dashboards:** Sukmandhani et al. (2022), Wollen et al. (2024), and Rahman et al. (2025) proved that web-based dashboards improve service management, transparency, and incident response times.

### 2.4 Hardware Deployment Strategy
- **Camera Placement:** Zhang et al. (2023) calculated camera spots using 3D grids.
- **Optical Parameters:** Van Dijk (2024) applied the DORI standard (Detect, Observe, Recognize, Identify) requiring $\ge 25$ Pixels Per Meter (PPM).
- **Multi-Camera Optimization:** Nguyen and Lee (2025) treated camera count as a spatial math problem to minimize overlapping views and network bandwidth usage.

### 2.5 Relevancy to the Study
Existing lost-and-found platforms rely entirely on manual user reporting, whereas modern surveillance systems focus purely on high-end security without item recovery logic. S.P.O.T.-IT bridges this gap by combining automated CCTV visual state change detection with a centralized microservices web dashboard for campus facility management.

---

## CHAPTER 3 RESEARCH DESIGN AND METHODOLOGY

### 3.1 Methodological Framework
The **Prototyping Model** was chosen as the methodological framework. It consists of four iterative phases:
1. **Phase 1: Requirement Analysis** – Gather physical lab room parameters, select IP CCTV cameras and hardware, evaluate local Wi-Fi, identify target rooms and baseline items, and interview laboratory personnel.
2. **Phase 2: System Design** – Camera placement and ROI mapping, count deviation detection logic design, baseline count registration design, dashboard UI/UX and database schema definition.
3. **Phase 3: Development & Integration** – Python + OpenCV detection module, Node.js + Express.js backend API, MySQL database hosted via XAMPP, web dashboard, and IoT hardware-to-software integration.
4. **Phase 4: Testing & Evaluation** – Functionality tests, accuracy tests (TP/FP/FN/TN across scenarios S1-S8), usability tests via Likert scales, and expert evaluations by IT faculty.

---

### 3.2 Data Gathering Procedure
Controlled monitoring experiments were conducted inside selected laboratory rooms within the Maria Salome Llanera Hall (CEAT Building) at DLSU-D.

#### Table 3.2: MLH Laboratory Room Equipment Inventory
| Room | Room Name | Equipment | Tier |
| :--- | :--- | :--- | :--- |
| MLH 407 | MMA Sound Production Laboratory | No equipment confirmed | Tier 4 |
| MLH 406 | MMA Cinema Laboratory | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 405 | MMA Creative Design Laboratory | iMac computers | Tier 3 |
| MLH 404 | MMA Film Production Laboratory | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 403 | MMA Animation Studio | Drawing tablet, keyboard, mouse, monitor | Tier 3 |
| MLH 401 | Architectural CAD | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 311 | Electronic Devices and Circuits 2 Lab | Oscilloscope, power supply, function generator | Tier 2 |
| MLH 306 | Systems and Applications Development Lab | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 305 | Logic and Algorithms Lab | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 304 | Engineering CAD | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 303 | Advanced Programming Lab | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 301 | Embedded Systems and Robotics Lab | Oscilloscope, power supply, function generator, mouse, keyboard, monitor, PC | Tier 2 |
| MLH 204 | Electronic Devices and Circuit 1 Lab | Oscilloscope, power supply, function generator | Tier 2 |
| MLH 203 | Computational Engineering Lab | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLH 202 | Wireless / Telecom Lab | Unknown equipment | Tier 4 |
| MLH 201 | Structured / Data Center Lab | Mouse, keyboard, monitor, PC, UPS | Tier 1 |
| MLB 111 | Mechanical Engineering Lab | Unknown equipment | Tier 4 |
| MLB 109 | CIM Lab | Unknown large equipment | Tier 4 |
| MLB 108 | Ergonomics Lab / Methods Study Lab | Treadmill (specialty equipment), unknown large equipment | Tier 3 |
| MLB 107 | IE/ME Computer Applications Lab | Mouse, keyboard, monitor, PC, UPS | Tier 1 |

#### Table 3.3: Frequently Lost Items inside the MLH Building
| Item | Tier |
| :--- | :--- |
| Bag / Pouch | Tier 1 |
| Calculator | Tier 2 |
| Cellphone | Tier 1 |
| Charger | Tier 1 |
| Clothes / Swimming Items | Tier 3 |
| Earbuds / Earphones | Tier 3 |
| Pencil Case | Tier 2 |
| Tablet Pen / Stylus | Tier 2 |
| Umbrella | Tier 1 |
| Wallet | Tier 1 |
| Water Bottle / Tumbler | Tier 1 |

---

### 3.3 Research Design
This study employs a quantitative experimental research design. Numerical data from detection results, monitoring logs, response times, and survey questionnaires were analyzed using descriptive statistics (frequency counts, percentages, and mean scores).

---

### 3.4 Research Instruments
- **Hardware:** Two IP CCTV cameras, a Tenda N300 wireless router, a Lenovo LOQ laptop (local processing unit), and a 2K Full HD USB webcam with microphone (claiming station).
- **Software:** Python and OpenCV (computer vision/detection module), Node.js and Express.js (backend RESTful API), PHP and phpMyAdmin (database management), HTML, CSS, Bootstrap, and JavaScript (web dashboard), Visual Studio Code, Postman, and XAMPP.

---

### 3.5 Testing Methodology
1. **Functionality Test:** Verifies component integration, event logging, snapshot capture, and dashboard display.
2. **Accuracy Test:** Evaluates performance via True Positives (TP), False Positives (FP), False Negatives (FN), and True Negatives (TN) across scenarios S1-S8.
3. **Usability Test:** Likert-scale questionnaires administered to students, staff, and IT evaluators.
4. **Expert Test:** Evaluation by IT faculty advisers on reliability and system practicality.

#### Table 3.5: Controlled Testing Scenarios
| Testing Scenario | Description | Expected System Response |
| :--- | :--- | :--- |
| Unattended Item Persistence | A monitored item is intentionally left inside the room. | Notification event generated |
| Minor Object Movement | An item is slightly moved within the ROI boundary. | No notification generated |
| Continuous Room Activity | Multiple individuals remain inside the room. | Monitoring temporarily pauses |
| Temporary Object Obstruction | An item is briefly covered or blocked. | No immediate false alert |
| Item Retrieval and Claiming | A detected item undergoes claiming verification. | Claim record data stored in database |
| Dashboard Notification Test | A valid unattended item event is generated. | Event displayed on dashboard |

---

### 3.6 System Process and Design
- **Block Diagram:** Divided into two subsystems (Laboratory Monitoring Subsystem and Dispensing Room Claiming Station Subsystem) communicating via HTTP POST to a centralized Node.js/Express API and MySQL database.
- **System Process & Flowcharts:** Continuous frame capture $
ightarrow$ Pre-processing $
ightarrow$ Scene stability check $
ightarrow$ ROI comparison $
ightarrow$ Candidate event creation $
ightarrow$ Database storage $
ightarrow$ Dashboard notification $
ightarrow$ Staff verification/claiming workflow.
- **OpenCV Prototype Validation & ROI Tolerance:** Implements allowed search zones (yellow bounding boxes) around registered ROIs (blue bounding boxes) to tolerate minor object shifts (e.g., matching score 0.73) without triggering false missing item alerts.
- **Database Design:** Microservices architecture divided into three sets: User Service Database, Room Monitoring Service Database, and Lost and Found Service Database.

---

### 3.7 System Components
#### 3.7.1 Hardware Components
1. **Dahua 5MP HD Full Color Eyeball CCTV Camera:** Full HD ($1920	imes1080$), 25-30 FPS, IR 30m, PoE, 3D DNR, DWDR.
2. **Tenda N300 Router:** Local wireless networking and internet connectivity.
3. **Lenovo LOQ 15IAX9 Laptop:** Local processing unit running Python/OpenCV and backend server.
4. **2K Full HD USB Webcam:** Captures documentation images during item claiming.

#### 3.7.2 Software Applications
Python, OpenCV, Node.js, Express.js, PHP, phpMyAdmin, HTML/CSS/Bootstrap/JS, VS Code, and XAMPP.

---

### 3.8 System Design
- **Software Process:** Background subtraction, frame differencing, and dual-logic filtering. Pauses during heavy room activity.
- **Hardware Placement:** Two cameras placed strategically in rooms (e.g., Computer Lab and Lab Room 2) to eliminate dead zones.
- **User Interface:** Landing page, index/features view, and secure login/signup interface requiring DLSU-D email credentials for role-based access control.

---

### 3.9 Selection of Samples
Convenience sampling was used to select 30 to 50 respondents (students, faculty members, staff, and IT evaluators) from DLSU-D CEAT Building facilities using Likert-scale questionnaires.

---

### 3.10 Ethical Considerations
Testing procedures complied with ethical and privacy standards. CCTV monitoring was restricted to authorized testing zones inside the CEAT Building. Captured snapshots are used exclusively for authorized documentation and staff verification, with secure database authentication.

---

## REFERENCES
- AI-Driven Lost and Found Management System for Institutions. (2026). *ResearchGate*.
- Alam, B. M. S., Mia, M. P., & Kibria, G. (2025). LabEquipVis: An annotated image dataset of computer laboratory equipment for object detection and smart lab automation. *Data in Brief*, 63, 112206.
- California State University. (2024). Property, equipment, and inventory: California State University, Dominguez Hills (Audit Report 23-31). *Office of the Chancellor*.
- CheckVideo, LLC. (2016). *CheckVideo camera placement guide* (7CVAMGC000E-05.01).
- Cob-Parro, A. C., et al. (2021). Smart Video Surveillance System Based on Edge Computing. *Sensors*, 21(9), 2958.
- David, N. M., et al. (2024). A Comparative Study on Lost and Found Management Systems in Academic Institutions... *2024 6th International Workshop on Artificial Intelligence and Education (WAIE)*, 230-236.
- DeHoratius, N., & Raman, A. (2008). Inventory Record Inaccuracy: An Empirical Analysis. *Management Science*, 54(4), 627-641.
- Detection Video Analytics - i3 Technologies. (2025). *i3 Philippines Systems Integrator*.
- Hussin, et al. (2012). Digital Image Processing Techniques for Object Detection From Complex Background Image. *Procedia Engineering*, 41, 340-344.
- Jr, B. C. (2022). Suspicious Object Detection with Alarm Notification for Security Personnel. *Psychology and Education: A Multidisciplinary Journal*, 3(10), 1-1.
- Juaneza, J. P. (2023). The development of a disruption responsive smart room technology with attendance management system. *IRJSTEM*, 3(1).
- Magtibay, O. (2021). Green switch: An IoT based energy monitoring system for mabini building in De La Salle Lipa. *ResearchGate*.
- Marique, L., Delyn, R., & Limani, J. (2025). Real-Time Object Detection for Smart City Surveillance and Traffic Management Systems. *ITE (Information Technology Engineering Journals)*, 10(1), 153-159.
- Multiple-object tracking based on movement direction assumption and potential re-appearance position with object flow visualizations. (2024). *Philippine Journal of Science, DOST*.
- Object detection in real-time video surveillance using attention based transformer-YOLOV8 model. (2025). *Alexandria Engineering Journal*, 118, 482-495.
- Ouardirhi, Z., Mahmoudi, S. A., & Zbakh, M. (2024). Enhancing object detection in smart Video Surveillance: A Survey of Occlusion-Handling Approaches. *Electronics*, 13(3), 541.
- Panchbhai, V. V., et al. (2024). Smart Attendance System: An Internet of Things (IoT)-Enabled Concept. *Cureus Journals*, 1(1).
- Qasim, A. M., et al. (2024). Abandoned object detection and classification using deep embedded vision. *IEEE Access*, 12, 35539-35551.
- Rahman, A., Lee, J., & Santos, M. (2025). IoT-integrated facility management system with real-time monitoring and fault detection. *IEEE Xplore Conference Proceedings*, 1-6.
- Russel, N. S., & Selvaraj, A. (2023). Ownership of abandoned object detection by integrating carried object recognition and context sensing. *The Visual Computer*, 40(6), 4401-4426.
- Sabelnikov, A. (2025). Methods of automatic detection of foreign objects in video surveillance. *SciSpace*.
- Sunthornnapa, T. (2025). Abandoned bag detection in public areas using Grounding DINO with fine-grained prompts. *Electrical Engineering*.
- T, A., et al. (2024). Campus Trace- Application for Lost and Found Items on Campus. *Journal of Trends in Computer Science and Smart Technology*, 6(2), 168-179.
- Tan, S. Y., & Chong, C. R. (2023). AN EFFECTIVE LOST AND FOUND SYSTEM IN UNIVERSITY CAMPUS. *Jistm*, 8(32).
- Van Den Hengel, A., et al. (2009). Automatic Camera Placement for Large Scale Surveillance Networks.
- Veluri, R. C., et al. (2024). Modified M-RCNN approach for abandoned object detection in public places. *Expert Systems*, 42(2).
- Walee, N. A. (2026). Enhancing AI-driven automation for object detection and computer vision. *Georgia Southern Commons*.
- Wang, Z., & Shao, Y. (2024). Real-time outdoor abandoned object detection algorithm based on tracking and detection. *IEEE Xplore*.
- Wollen, J., et al. (2025). Development of a customizable Web-Based Dashboard for Remote Blood Pressure Monitoring: Feasibility and Usability Implementation study. *JMIR Formative Research*, 9, e62700.
- Wu, S., et al. (2023). Shadow Hunter: Low-Illumination Object-Detection Algorithm. *Applied Sciences*, 13(16), 9261.
- Yan, G., et al. (2022). CamFi: An AI-driven and Camera-based System for Assisting Users in Finding Lost Objects in Multi-Person Scenarios. *CHI Conference Extended Abstracts*, 1-7.
- Yilmazer, M., & Karakose, M. (2025). A new, robust, adaptive, versatile, and scalable abandoned object detection approach based on DeepSORT dynamic prompts... *Applied Sciences*, 15(5), 2774.
- YOLO-based human detection and tracking application for contact tracing... (2023). *Manuel S. Enverga University Foundation*.
- Zhou, L., & Xu, J. (2024). Enhanced abandoned object detection through adaptive dual-background modeling and SAO-YOLO integration. *Sensors*, 24(20), 6572.
- Zhou, M., et al. (2023). LostNet: A smart way for lost and find. *arXiv*.
